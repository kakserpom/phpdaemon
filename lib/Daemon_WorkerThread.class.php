<?php

/**
 * Implementation of the worker thread
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon_WorkerThread extends Thread {

	public $update = FALSE;
	public $reload = FALSE;
	public $reloadTime = 0;
	private $reloadDelay = 2;
	public $reloaded = FALSE;
	public $pool = array();
	public $poolApp = array();
	public $connCounter = 0;
	public $reqCounter = 0;
	public $queue = array();
	public $timeLastReq = 0;
	public $readPoolState = array();
	public $writePoolState = array();
	private $autoReloadLast = 0;
	private $currentStatus = 0;
	public $eventBase;
	public $timeoutEvent;
	public $status = 0;
	public $breakMainLoop = FALSE;
	public $reloadReady = FALSE;
	public $delayedSigReg = TRUE;
	public $instancesCount = array();
	public $connection;
	
	/**
	 * Runtime of Worker process.
	 * @return void
	 */
	protected function run() {
		Daemon::$process = $this;
		$this->autoReloadLast = time();
		$this->reloadDelay = Daemon::$config->mpmdelay->value + 2;
		$this->setStatus(4);

		if (Daemon::$config->autogc->value > 0) {
			gc_enable();
		} else {
			gc_disable();
		}
		
		$this->prepareSystemEnv();
		$this->overrideNativeFuncs();
	
		$this->setStatus(6);
		$this->eventBase = event_base_new();
		$this->registerEventSignals();

		$this->IPCManager = Daemon::$appResolver->getInstanceByAppName('IPCManager');
		Daemon::$appResolver->preload();
	
		foreach (Daemon::$appInstances as $app) {
			foreach ($app as $appInstance) {
				if (!$appInstance->ready) {
					$appInstance->ready = TRUE;
					$appInstance->onReady();
				}
			}
		}

		$this->setStatus(1);

		/**
		 * @closure readPoolEvent
		 * @description Invokes the AppInstance->readConn() method for every updated connection in pool. readConn() reads new data from the buffer.
		 * @return void
		 */
		$this->readPoolEvent = Daemon_TimedEvent::add(function($event) {
			$self = Daemon::$process;
			
			foreach ($self->readPoolState as $connId => $state) {
				if (Daemon::$config->logevents->value) {
					$self->log('event readConn(' . $connId . ') invoked.');
				}

				$self->poolApp[$connId]->readConn($connId);

				if (Daemon::$config->logevents->value) {
					$self->log('event readConn(' . $connId . ') finished.');
				}
			}
			
			if (sizeof($self->readPoolState) > 0) {
				$event->timeout();
			}
		}, pow(10,6) * 0.005, 'readPoolEvent');
		
		Daemon_TimedEvent::add(function($event) {
			$self = Daemon::$process;
			
			if ($self->checkState() !== TRUE) {
				$self->closeSockets();
				$self->breakMainLoop = TRUE;
				event_base_loopexit($self->eventBase);
				return;
			}
			
			$event->timeout();
		}, pow(10,6) * 1,	'checkStateTimedEvent');
		
		if (Daemon::$config->autoreload->value > 0) {
			Daemon_TimedEvent::add(function($event) {
				$self = Daemon::$process;
					
				static $n = 0;
			
				$inc = array_unique(array_map('realpath',get_included_files()));
				$s = sizeof($inc);
				if ($s > $n) {
					$slice = array_slice($inc,$n);
					Daemon::$process->IPCManager->sendPacket(array('op' => 'addIncludedFiles', 'files' => $slice));
					$n = $s;
				}
				$event->timeout();
			}, pow(10,6) * Daemon::$config->autoreload->value, 'watchIncludedFilesTimedEvent');
		}
			

		while (!$this->breakMainLoop) {
			event_base_loop($this->eventBase);
		}
	}
	/**
	 * Overrides native PHP functions.
	 * @return void
	 */
	public function overrideNativeFuncs() {
		if (Daemon::supported(Daemon::SUPPORT_RUNKIT_INTERNAL_MODIFY)) {
			
			
			runkit_function_rename('header', 'header_native');

			function header() { 
				if (
					Daemon::$req
					&& Daemon::$req instanceof HTTPRequest
				) {
					return call_user_func_array(array(Daemon::$req, 'header'), func_get_args());
				}
			}
			
			runkit_function_rename('is_uploaded_file', 'is_uploaded_file_native');

			function is_uploaded_file() { 
				if (
					Daemon::$req
					&& Daemon::$req instanceof HTTPRequest
				) {
					return call_user_func_array(array(Daemon::$req, 'isUploadedFile'), func_get_args());
				}
			}
			
			
			runkit_function_rename('move_uploaded_file', 'move_uploaded_file_native');

			function move_uploaded_file() { 
				if (
					Daemon::$req
					&& Daemon::$req instanceof HTTPRequest
				) {
					return call_user_func_array(array(Daemon::$req, 'moveUploadedFile'), func_get_args());
				}
			}			


			runkit_function_rename('headers_sent', 'headers_sent_native');

			function headers_sent() { 
				if (
					Daemon::$req
					&& Daemon::$req instanceof HTTPRequest
				) {
					return Daemon::$req->headers_sent();
				}
			}

			runkit_function_rename('headers_list', 'headers_list_native');

			function headers_list() { 
				if (
					Daemon::$req
					&& Daemon::$req instanceof HTTPRequest
				) {
					return Daemon::$req->headers_list();
				}
			}

			runkit_function_rename('setcookie', 'setcookie_native');

			function setcookie() { 
				if (
					Daemon::$req
					&& Daemon::$req instanceof HTTPRequest
				) {
					return call_user_func_array(array(Daemon::$req, 'setcookie'), func_get_args());
				}
			}
		
			runkit_function_rename('register_shutdown_function', 'register_shutdown_function_native');

			function register_shutdown_function($cb) {
				if (Daemon::$req) {
					return Daemon::$req->registerShutdownFunction($cb);
				}
			}

			runkit_function_copy('create_function', 'create_function_native');
			runkit_function_redefine('create_function', '$arg,$body', 'return __create_function($arg,$body);');
			
			function __create_function($arg, $body) {
				static $cache = array();
				static $maxCacheSize = 64;
				static $sorter;
				
				if ($sorter === NULL) {
					$sorter = function($a, $b) {
						if ($a->hits == $b->hits) {
							return 0;
						}

						return ($a->hits < $b->hits) ? 1 : -1;
					};
				}
				
				$crc = crc32($arg . "\x00" . $body);

				if (isset($cache[$crc])) {
					++$cache[$crc]->hits;
	
					return $cache[$crc];
				}
				
				if (sizeof($cache) >= $maxCacheSize) {
					uasort($cache, $sorter);
					array_pop($cache);
				}
	
				return $cache[$crc] = new DestructableLambda(create_function_native($arg, $body));
			}
		}
	}
	
	/**
	 * Setup settings on start.
	 * @return void
	 */
	public function prepareSystemEnv() {
		proc_nice(Daemon::$config->workerpriority->value);
		
		$this->setproctitle(
			Daemon::$runName . ': worker process' 
			. (Daemon::$config->pidfile->value !== Daemon::$config->defaultpidfile->value 
				? ' (' . Daemon::$config->pidfile->value . ')' : '')
		);
		
		if (isset(Daemon::$config->group->value)) {
			$sg = posix_getgrnam(Daemon::$config->group->value);
		}
		
		if (isset(Daemon::$config->user->value)) {
			$su = posix_getpwnam(Daemon::$config->user->value);
		}
	
		if (Daemon::$config->chroot->value !== '/') {
			if (posix_getuid() != 0) {
				Daemon::log('You must have the root privileges to change root.');
				exit(0);
			}
			elseif (!chroot(Daemon::$config->chroot->value)) {
				Daemon::log('Couldn\'t change root to \'' . Daemon::$config->chroot->value . '\'.');
				exit(0);
			}
		}
		
		if (isset(Daemon::$config->group->value)) {
			if ($sg === FALSE) {
				Daemon::log('Couldn\'t change group to \'' . Daemon::$config->group->value . '\'. You must replace config-variable \'group\' with existing group.');
				exit(0);
			}
			elseif (
				($sg['gid'] != posix_getgid()) 
				&& (!posix_setgid($sg['gid']))
			) {
				Daemon::log('Couldn\'t change group to \'' . Daemon::$config->group->value . "'. Error (" . ($errno = posix_get_last_error()) . '): ' . posix_strerror($errno));
				exit(0);
			}
		}
		
		if (isset(Daemon::$config->user->value)) {
			if ($su === FALSE) {
				Daemon::log('Couldn\'t change user to \'' . Daemon::$config->user->value . '\', user not found. You must replace config-variable \'user\' with existing username.');
				exit(0);
			}
			elseif (
				($su['uid'] != posix_getuid()) 
				&& (!posix_setuid($su['uid']))
			) {
				Daemon::log('Couldn\'t change user to \'' . Daemon::$config->user->value . "'. Error (" . ($errno = posix_get_last_error()) . '): ' . posix_strerror($errno));
				exit(0);
			}
		}
		
		if (Daemon::$config->cwd->value !== '.') {
			if (!@chdir(Daemon::$config->cwd->value)) {
				Daemon::log('Couldn\'t change directory to \'' . Daemon::$config->cwd->value . '.');
			}
		}
		register_shutdown_function(array($this,'shutdown'));
	}
	
	/**
	 * Log something
	 * @return void
	 */
	public function log($message) {
		Daemon::log('#' . $this->pid . ' ' . $message);
	}
	
	/**
	 * Close each of binded sockets.
	 * @return void
	 */
	public function closeSockets() {
		foreach (Daemon::$socketEvents as $k => $ev) {
			event_del($ev);
			event_free($ev);

			unset($this->socketEvents[$k]);
		}

		foreach (Daemon::$sockets as $k => &$s) {
			if (Daemon::$useSockets) {
				socket_close($s[0]);
			} else {
				fclose($s[0]);
			}
		
			unset(Daemon::$sockets[$k]);
		}
	}
	
	/**
	 * Reloads additional config-files on-the-fly.
	 * @return void
	 */
	private function update() {
		foreach (Daemon::$appInstances as $k => $app) {
			foreach ($app as $appInstance) {
				$appInstance->handleStatus(2);
			}
		}
	}

	/**
	 * @todo description?
	 */
	public function checkState() {
		$time = microtime(true);

		if ($this->terminated) {
			return FALSE;
		} 
		
		if ($this->status > 0) {
			return $this->status;
		}

		if (
			Daemon::$config->maxrequests->value
			&& ($this->reqCounter >= Daemon::$config->maxrequests->value)
		) {
			$this->log('\'maxrequests\' exceed. Graceful shutdown.');

			$this->reload = TRUE;
			$this->reloadTime = $time + $this->reloadDelay;
			$this->setStatus($this->currentStatus);
			$this->status = 3;
		}
	
		if (
			(Daemon::$config->maxmemoryusage->value > 0) 
			&& (memory_get_usage(TRUE) > Daemon::$config->maxmemoryusage->value)
		) {
			$this->log('\'maxmemory\' exceed. Graceful shutdown.');

			$this->reload = TRUE;
			$this->reloadTime = $time + $this->reloadDelay;
			$this->setStatus($this->currentStatus);
			$this->status = 3;
		}
	
		if (
			Daemon::$config->maxidle->value 
			&& $this->timeLastReq 
			&& ($time - $this->timeLastReq > Daemon::$config->maxidle->value)
		) {
			$this->log('\'maxworkeridle\' exceed. Graceful shutdown.');

			$this->reload = TRUE;
			$this->reloadTime = $time + $this->reloadDelay;
			$this->setStatus($this->currentStatus);
			$this->status = 3;
		}
	
		if ($this->update === TRUE) {
			$this->update = FALSE;
			$this->update();
		}

		if ($this->shutdown === TRUE) {
			$this->status = 5;
		}
	
		if (
			($this->reload === TRUE) 
			&& ($time > $this->reloadTime)
		) {
			$this->status = 6;
		}

		if ($this->status > 0) {
			foreach (Daemon::$appInstances as $app) {
				foreach ($app as $appInstance) {
					$appInstance->handleStatus($this->status);
				}
			}

			return $this->status;
		}

		return TRUE;
	}
	
	/**
	 * Asks the running applications the whether we can go to shutdown current (old) worker.
	 * @return boolean - Ready?
	 */
	public function appInstancesReloadReady() {
		$ready = TRUE;
		
		foreach (Daemon::$appInstances as $k => $app) {
			foreach ($app as $appInstance) {
				if (!$appInstance->handleStatus($this->currentStatus)) {
					$this->log(__METHOD__ . ': waiting for ' . $k);
				
					$ready = FALSE;
				}
			}
		}
		return $ready;
	}
	
	/**
	 * @todo description?
	 * @param boolean - Hard? If hard, we shouldn't wait for graceful shutdown of the running applications.
	 * @return boolean - Ready?
	 */
	public function shutdown($hard = FALSE) {
		if (Daemon::$config->logevents->value) {
			$this->log('event shutdown(' . ($hard ? 'HARD' : '') . ') invoked.');
		}
		
		if (Daemon::$config->throwexceptiononshutdown->value) {
			throw new Exception('event shutdown');
		}
		
		@ob_flush();
		
		if ($this->terminated === TRUE) {
			if ($hard) {
				exit(0);
			}

			return;
		}
		
		$this->terminated = TRUE;
		$this->closeSockets();
		$this->setStatus(3);
		
		if ($hard) {
			exit(0);
		}
		
		$this->reloadReady = $this->appInstancesReloadReady();

		if ($this->reload === TRUE) {
			$this->reloadReady = $this->reloadReady && (microtime(TRUE) > $this->reloadTime);
		}

		if (Daemon::$config->logevents->value) {
			$this->log('reloadReady = ' . Debug::dump($this->reloadReady));
		}
		
		foreach ($this->queue as $r) {
			if ($r instanceof stdClass) {
				continue;
			}

			if ($r->running) {
				$r->finish(-2);
			}
		}
		
		$n = 0;
		
		unset($this->timeouts['checkStateTimedEvent']);
		
		Daemon_TimedEvent::add(function($event) 	{
			$self = Daemon::$process;
				
			$self->reloadReady = $self->appInstancesReloadReady();
					
			if ($self->reload === TRUE) {
				$self->reloadReady = $self->reloadReady && (microtime(TRUE) > $self->reloadTime);
			}
				
			$event->timeout();
		}, pow(10,6), 'checkReloadReady');
		
		while (!$this->reloadReady) {
			event_base_loop($this->eventBase);
		}
		
		posix_kill(posix_getppid(), SIGCHLD);
		exit(0);
	}

	/**
	 * Changes the worker's status.	
	 * @param int - Integer status.
	 * @return boolean - Success.
	 */
	public function setStatus($int) {
		if (!$this->spawnid) {
			return FALSE;
		}
		
		$this->currentStatus = $int;
		
		if ($this->reload) {
			$int += 100;
		}
		
		if (Daemon::$config->logworkersetstatus->value) {
			$this->log('status is ' . $int);
		}
		
		return shmop_write(Daemon::$shm_wstate, chr($int), $this->spawnid - 1);
	}
	
	/**
	 * Handler of the SIGINT (hard shutdown) signal in worker process.
	 * @return void
	 */
	protected function sigint() {
		if (Daemon::$config->logsignals->value) {
			$this->log('caught SIGINT.');
		}
		
		$this->shutdown(TRUE);
	}
	
	/**
	 * Handler of the SIGTERM (graceful shutdown) signal in worker process.
	 * @return void
	 */
	protected function sigterm() {
		if (Daemon::$config->logsignals->value) {
			$this->log('caught SIGTERM.');
		}
		
		$this->shutdown();
	}
	
	/**
	 * Handler of the SIGQUIT (graceful shutdown) signal in worker process.
	 * @return void
	 */
	public function sigquit() {
		if (Daemon::$config->logsignals->value) {
			$this->log('caught SIGQUIT.');
		}

		parent::sigquit();	
	}
	
	/**
	 * Handler of the SIGHUP (reload config) signal in worker process.
	 * @return void
	 */
	public function sighup() {
		if (Daemon::$config->logsignals->value) {
			$this->log('caught SIGHUP (reload config).');
		}

		if (isset(Daemon::$config->configfile->value)) {
			Daemon::loadConfig(Daemon::$config->configfile->value);
		}

		$this->update = TRUE;
	}

	/**
	 * Handler of the SIGUSR1 (re-open log-file) signal in worker process.
	 * @return void
	 */
	public function sigusr1() {
		if (Daemon::$config->logsignals->value) {
			$this->log('caught SIGUSR1 (re-open log-file).');
		}
	
		Daemon::openLogs();
	}
	
	/**
	 * Handler of the SIGUSR2 (graceful shutdown for update) signal in worker process.
	 * @return void
	 */
	public function sigusr2() {
		if (Daemon::$config->logsignals->value) {
			$this->log('caught SIGUSR2 (graceful shutdown for update).');
		}

		$this->reload = TRUE;
		$this->reloadTime = microtime(TRUE) + $this->reloadDelay;
		$this->setStatus($this->currentStatus);
	}

	/**
	 * Handler of the SIGTTIN signal in worker process.
	 * @return void
	 */
	public function sigttin() { }
	
	/**
	 * Handler of the SIGXSFZ ignal in worker process.
	 * @return void
	 */
	public function sigxfsz() {
		$this->log('SIGXFSZ.');
	}
	
	/**
	 * Handler of non-known signals.
	 * @return void
	 */
	public function sigunknown($signo) {
		if (isset(Thread::$signals[$signo])) {
			$sig = Thread::$signals[$signo];
		} else {
			$sig = 'UNKNOWN';
		}
		
		$this->log('caught signal #' . $signo . ' (' . $sig . ').');
	}
	
	/**
	 * Destructor of worker thread.
	 * @return void
	 */
	public function __destruct()
	{
		$this->setStatus(0x03);
	}
	
}
