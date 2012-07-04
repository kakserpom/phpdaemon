<?php

/**
 * Implementation of the master thread
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon_MasterThread extends Thread {

	public $delayedSigReg = TRUE;
	public $breakMainLoop = FALSE;
	public $reload = FALSE;
	public $connCounter = 0;
	public $fileWatcher;
	public $callbacks;
	
	/**
	 * Runtime of Master process
	 * @return void
	 */
	public function run() {
		Daemon::$process = $this;
		
		$this->prepareSystemEnv();
		class_exists('Timer'); // ensure loading this class
		
		gc_enable();
		
		$this->eventBase = event_base_new();
		$this->registerEventSignals();
		FS::initEvent();

		$this->fileWatcher = new FileWatcher;
		$this->workers = new ThreadCollection;
		$this->collections['workers'] = $this->workers;
		
		Daemon::$appResolver = require Daemon::$config->path->value;
		$this->IPCManager = Daemon::$appResolver->getInstanceByAppName('IPCManager');
		Daemon::$appResolver->preload(true); 

		$this->callbacks = new SplStack;
		$this->spawnWorkers(min(
			Daemon::$config->startworkers->value,
			Daemon::$config->maxworkers->value
		));
		Timer::add(function($event) use (&$cbs) {
			$self = Daemon::$process;

			static $c = 0;
			
			++$c;
			
			if ($c > 0xFFFFF) {
				$c = 1;
			}
				
			if (($c % 10 == 0)) {
				$self->workers->removeTerminated(true);
				gc_collect_cycles();
			} else {
				$self->workers->removeTerminated();
			}
			
			if (
				isset(Daemon::$config->mpm->value) 
				&& is_callable(Daemon::$config->mpm->value)
			) {
				call_user_func(Daemon::$config->mpm->value);
			} else {
				// default MPM
				$state = Daemon::getStateOfWorkers($self);
				
				if ($state) {
					$n = max(
						min(
							Daemon::$config->minspareworkers->value - $state['idle'], 
							Daemon::$config->maxworkers->value - $state['alive']
						),
						Daemon::$config->minworkers->value - $state['alive']
					);

					if ($n > 0) {
						Daemon::log('Spawning ' . $n . ' worker(s).');
						$self->spawnWorkers($n);
						event_base_loopbreak($self->eventBase);
					}

					$n = min(
						$state['idle'] - Daemon::$config->maxspareworkers->value,
						$state['alive'] - Daemon::$config->minworkers->value
					);
					
					if ($n > 0) {
						Daemon::log('Stopping ' . $n . ' worker(s).');
						$self->stopWorkers($n);
					}
				}
			}
			
			
			$event->timeout();
		}, 1e6 * Daemon::$config->mpmdelay->value, 'MPM');
		
		

		while (!$this->breakMainLoop) {
			while (!$this->callbacks->isEmpty()) {
				call_user_func($this->callbacks->shift(), $this);
			}
			event_base_loop($this->eventBase);
		}
	}
	public function updatedWorkers() {
	
		$perWorker = 1;
		$instancesCount = array();
		foreach (Daemon::$config as $name => $section)
		{
		 if (
			(!$section instanceof Daemon_ConfigSection)
			|| !isset($section->limitinstances)) {
			
				continue;
			}
			$instancesCount[$name] = 0;
		}
		foreach ($this->workers->threads as $worker) {
			foreach ($worker->instancesCount as $k => $v) {
				if (!isset($instancesCount[$k])) {
					unset($worker->instancesCount[$k]);
					continue;
				}
				$instancesCount[$k] += $v;
			}
		}
		foreach ($instancesCount as $name => $num) {
			$v = Daemon::$config->{$name}->limitinstances->value - $num;
			foreach ($this->workers->threads as $worker) {
					if ($v <= 0) {break;}
					if ((isset($worker->instancesCount[$name])) && ($worker->instancesCount[$name] < $perWorker) || !isset($worker->connection))	{
						continue;
					}
					if (!isset($worker->instancesCount[$name])) {
						$worker->instancesCount[$name] = 1;
					}
					else {
						++$worker->instancesCount[$name];
					}
					$worker->connection->sendPacket(array('op' => 'spawnInstance', 'appfullname' => $name));
					--$v;
			}
		}
	}
	
	/**
	 * Log something
	 * @param string - Message.
	 * @return void
	 */
	public function log($message) {
		Daemon::log('M#' . $this->pid . ' ' . $message);
	}


	/**
	 * Setup settings on start.
	 * @return void
	 */
	public function prepareSystemEnv() {
	
		register_shutdown_function(array($this,'onShutdown'));
		
		proc_nice(Daemon::$config->masterpriority->value);
		
		$this->setproctitle(
			Daemon::$runName . ': master process' 
			. (Daemon::$config->pidfile->value !== Daemon::$config->pidfile->defaultValue ? ' (' . Daemon::$config->pidfile->value . ')' : '')
		);
	}
	/**
	 * @todo description missed
	 */	
	public function reloadWorker($spawnId) {
		if (isset($this->workers->threads[$spawnId])) {
			if (!$this->workers->threads[$spawnId]->reloaded) {
				Daemon::log('Spawning worker-replacer for reloaded worker #' . $spawnId . '.');
				$this->spawnWorkers(1);
				$this->workers->threads[$spawnId]->reloaded = true;
			}
		}
	}
	
	/**
	 * Spawn new worker processes
	 * @param $n - integer - number of workers to spawn
	 * @return boolean - success
	 */
	public function spawnWorkers($n) {
		if (FS::$supported) {
			eio_event_loop();
		}
		$n = (int) $n;
	
		for ($i = 0; $i < $n; ++$i) {
			$thread = new Daemon_WorkerThread;
			$this->workers->push($thread);

			$this->callbacks->push(function($self) use ($thread) {
				$pid = $thread->start();
				if ($pid < 0) {
					Daemon::log('could not fork worker');
				} elseif ($pid === 0) { // worker
					Daemon::log('Unexcepted execution return to outside of Thread_start()');
					exit;
				}
			});

		}

		return true;
	}

	/**
	 * Stop the workers
	 * @param $n - integer - number of workers to stop
	 * @return boolean - success
	 */
	public function stopWorkers($n = 1) {
		$n = (int) $n;
		$i = 0;

		foreach ($this->workers->threads as &$w) {
			if ($i >= $n) {
				break;
			}

			if ($w->shutdown) {
				continue;
			}

			$w->stop();
			++$i;
		}
		
		return true;
	}
	
	/**
	 * Called when master is going to shutdown
	 * @todo -> protected?
	 * @return void
	 */
	public function onShutdown() {
		if ($this->pid != posix_getpid()) {
			return;
		}

		if ($this->shutdown === true) {
			return;
		}

		Daemon::log('Unexcepted master shutdown.'); 

		$this->shutdown(SIGTERM);
	}

	/**
	 * Called when master is going to shutdown
	 * @param integer System singal's number
	 * @return void
	 */
	public function shutdown($signo = false) {
		$this->shutdown = true;
		$this->waitAll(true);

		if (Daemon::$shm_wstate) {
			shmop_delete(Daemon::$shm_wstate);
		}
		
		file_put_contents(Daemon::$config->pidfile->value,'');
		
		exit(0);
	}
	
	/**
	 * Handler for the SIGCHLD (child is dead) signal in master process.
	 * @todo +on?
	 * @return void
	 */
	protected function sigchld() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGCHLD.');
		}

		parent::sigchld();
	}

	/**
	 * Handler for the SIGINT (shutdown) signal in master process. Shutdown.
	 * @todo +on ?
	 * @return void
	 */
	protected function sigint() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGINT.');
		}
	
		$this->workers->signal(SIGINT);
		$this->shutdown(SIGINT);
	}
	
	/**
	 * Handler for the SIGTERM (shutdown) signal in master process
	 * @todo +on & -> protected?
	 * @return void
	 */
	protected function sigterm() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGTERM.');
		}
	
		$this->workers->signal(SIGTERM);
		$this->shutdown(SIGTERM);
	}
	
	/**
	 * Handler for the SIGQUIT signal in master process
	 * @todo +on & -> protected?
	 * @return void
	 */
	protected function sigquit() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGQUIT.');
		}

		$this->workers->signal(SIGQUIT);
		$this->shutdown(SIGQUIT);
	}

	/**
	 * Handler for the SIGHUP (reload config) signal in master process
	 * @todo +on & -> protected?
	 * @return void
	 */
	public function sighup() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGHUP (reload config).');
		}

		if (isset(Daemon::$config->configfile)) {
			Daemon::loadConfig(Daemon::$config->configfile->value);
		}

		$this->workers->signal(SIGHUP);
	}

	/**
	 * Handler for the SIGUSR1 (re-open log-file) signal in master process
	 * @todo +on & -> protected?
	 * @return void
	 */
	public function sigusr1() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGUSR1 (re-open log-file).');
		}

		Daemon::openLogs();
		$this->workers->signal(SIGUSR1);
	}

	/**
	 * Handler for the SIGUSR2 (graceful restart all workers) signal in master process
	 * @todo +on & -> protected?
	 * @return void
	 */
	public function sigusr2() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGUSR2 (graceful restart all workers).');
		}

		$this->workers->signal(SIGUSR2);
	}

	/**
	 * Handler for the SIGTTIN signal in master process
	 * @todo not used or -> protected?
	 * @return void
	 */
	public function sigttin() { }

	/**
	 * Handler for the SIGXSFZ signal in master process
	 * @todo +on & -> protected?
	 * @return void
	 */
	public function sigxfsz() {
		Daemon::log('Master caught SIGXFSZ.');
	}
	
	/**
	 * Handler for non-known signals
	 * @todo +on & -> protected?
	 * @return void
	 */
	public function sigunknown($signo) {
		if (isset(Thread::$signals[$signo])) {
			$sig = Thread::$signals[$signo];
		} else {
			$sig = 'UNKNOWN';
		}

		Daemon::log('Master caught signal #' . $signo . ' (' . $sig . ').');
	}

}
