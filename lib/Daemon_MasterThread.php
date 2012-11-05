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
	public $callbacks;
	public $workers;
	public $ipcthreads;
	
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

		$this->workers = new ThreadCollection;
		$this->collections['workers'] = $this->workers;
		$this->ipcthreads = new ThreadCollection;
		$this->collections['ipcthreads'] = $this->ipcthreads;
		
		Daemon::$appResolver = require Daemon::$appResolverPath;
		Daemon::$appResolver->preload(true); 

		$this->callbacks = new StackCallbacks;
		$this->spawnIPCThread();
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
				$self->ipcthreads->removeTerminated(true);
				gc_collect_cycles();
			} else {
				$self->workers->removeTerminated();
				$self->ipcthreads->removeTerminated();
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
			$this->callbacks->executeAll($this);
			event_base_loop($this->eventBase);
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

		posix_setsid();
		proc_nice(Daemon::$config->masterpriority->value);
		if (!Daemon::$config->verbosetty->value) {
     		fclose(STDIN);
        	fclose(STDOUT);
        	fclose(STDERR);
        }
		
		$this->setproctitle(
			Daemon::$runName . ': master process' 
			. (Daemon::$config->pidfile->value !== Daemon::$config->pidfile->defaultValue ? ' (' . Daemon::$config->pidfile->value . ')' : '')
		);
	}
	/**
	 * @todo description missed
	 */	
	public function reloadWorker($id) {
		if (isset($this->workers->threads[$id])) {
			if (!$this->workers->threads[$id]->reloaded) {
				Daemon::$process->log('Spawning worker-replacer for reloaded worker #' . $id);
				$this->spawnWorkers(1);
				$this->workers->threads[$id]->reloaded = true;
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
					Daemon::$process->log('could not fork worker');
				} elseif ($pid === 0) { // worker
					Daemon::log('Unexcepted execution return to outside of Thread->start()');
					exit;
				}
			});

		}
		if ($n > 0) {
			event_base_loopbreak($this->eventBase);
		}
		return true;
	}

		/**
	 * Spawn IPC process
	 * @param $n - integer - number of workers to spawn
	 * @return boolean - success
	 */
	public function spawnIPCThread() {
		if (FS::$supported) {
			eio_event_loop();
		}
		$thread = new Daemon_IPCThread;
		$this->ipcthreads->push($thread);

		$this->callbacks->push(function($self) use ($thread) {
			$pid = $thread->start();
			if ($pid < 0) {
				Daemon::$process->log('could not fork IPCThread');
			} elseif ($pid === 0) { // worker
				$this->log('Unexcepted execution return to outside of Thread->start()');
				exit;
			}
		});

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

		$this->log('Unexcepted shutdown.'); 

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
			$this->log('Caught SIGCHLD.');
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
			$this->log('Caught SIGINT.');
		}
	
		$this->signalToChildren(SIGINT);
		$this->shutdown(SIGINT);
	}
	
	public function signalToChildren($signo) {
		foreach ($this->collections as $col) {
			$col->signal($signo);
		}

	}
	/**
	 * Handler for the SIGTERM (shutdown) signal in master process
	 * @todo +on & -> protected?
	 * @return void
	 */
	protected function sigterm() {
		if (Daemon::$config->logsignals->value) {
			$this->log('Caught SIGTERM.');
		}
	
		$this->signalToChildren(SIGTERM);
		$this->shutdown(SIGTERM);
	}
	
	/**
	 * Handler for the SIGQUIT signal in master process
	 * @todo +on & -> protected?
	 * @return void
	 */
	protected function sigquit() {
		if (Daemon::$config->logsignals->value) {
			$this->log('Caught SIGQUIT.');
		}

		$this->signalToChildren(SIGQUIT);
		$this->shutdown(SIGQUIT);
	}

	/**
	 * Handler for the SIGHUP (reload config) signal in master process
	 * @todo +on & -> protected?
	 * @return void
	 */
	public function sighup() {
		if (Daemon::$config->logsignals->value) {
			$this->log('Caught SIGHUP (reload config).');
		}

		if (isset(Daemon::$config->configfile)) {
			Daemon::loadConfig(Daemon::$config->configfile->value);
		}

		$this->signalToChildren(SIGHUP);
	}

	/**
	 * Handler for the SIGUSR1 (re-open log-file) signal in master process
	 * @todo +on & -> protected?
	 * @return void
	 */
	public function sigusr1() {
		if (Daemon::$config->logsignals->value) {
			$this->log('Caught SIGUSR1 (re-open log-file).');
		}

		Daemon::openLogs();
		$this->signalToChildren(SIGUSR1);
	}

	/**
	 * Handler for the SIGUSR2 (graceful restart all workers) signal in master process
	 * @todo +on & -> protected?
	 * @return void
	 */
	public function sigusr2() {
		if (Daemon::$config->logsignals->value) {
			$this->log('Caught SIGUSR2 (graceful restart all workers).');
		}

		$this->signalToChildren(SIGUSR2);
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
		$this->log('Caught SIGXFSZ.');
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

		$this->log('Caught signal #' . $signo . ' (' . $sig . ').');
	}

}
