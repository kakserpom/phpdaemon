<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Daemon_MasterThread
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description Implementation of the master thread.
/**************************************************************************/

class Daemon_MasterThread extends Thread {

	/**
	 * Runtime of Master process
	 * @return void
	 */
	protected function run() {
		proc_nice(Daemon::$config->masterpriority->value);
		gc_enable();
		register_shutdown_function(array($this,'onShutdown'));
		$this->collections = array('workers' => new ThreadCollection);

		$this->setproctitle(
			Daemon::$runName . ': master process' 
			. (Daemon::$config->pidfile->value !== Daemon::$config->pidfile->defaultValue ? ' (' . Daemon::$config->pidfile->value . ')' : '')
		);

		Daemon::$appResolver = require Daemon::$config->path->value;
		Daemon::$appResolver->preload(true); 

		$this->spawnWorkers(min(
			Daemon::$config->startworkers->value,
			Daemon::$config->maxworkers->value
		));

		$mpmLast = $autoReloadLast = time();
		$c = 1;

		while (true) {
			pcntl_signal_dispatch();
			$this->sigwait(1,0);
			clearstatcache();
      
			if (
				isset(Daemon::$config->configfile) 
				&& (Daemon::$config->autoreload->value > 0)
			) {
				$mt = filemtime(Daemon::$config->configfile->value);

				if (!isset($cfgmtime)) {
					$cfgmtime = $mt;
				}

				if ($cfgmtime < $mt) {
					$cfgmtime = filemtime(Daemon::$config->configfile->value);
					$this->sighup();
				}
			}

			$time = time();
			
			if ($time > $mpmLast+Daemon::$config->mpmdelay->value) {
				$mpmLast = $time;
				++$c;
				
				if ($c > 0xFFFFF) {
					$c = 0;
				}
				
				if (($c % 10 == 0)) {
					$this->collections['workers']->removeTerminated(true);
					gc_collect_cycles();
				} else {
					$this->collections['workers']->removeTerminated();
				}
				
				if (
					isset(Daemon::$config->mpm->value) 
					&& is_callable(Daemon::$config->mpm->value)
				) {
					call_user_func(Daemon::$config->mpm->value);
				} else {
					// default MPM
					$state = Daemon::getStateOfWorkers($this);
					
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
							$this->spawnWorkers($n);
						}

						$n = min(
							$state['idle'] - Daemon::$config->maxspareworkers->value,
							$state['alive'] - Daemon::$config->minworkers->value
						);
						
						if ($n > 0) {
							Daemon::log('Stopping ' . $n . ' worker(s).');
							$this->stopWorkers($n);
						}
					}
				}
			}
		}
	}

	/**
	 * FIXME description missed
	 */	
	public function reloadWorker($spawnId) {
		if (isset($this->collections['workers']->threads[$spawnId])) {
			if (!$this->collections['workers']->threads[$spawnId]->reloaded) {
				Daemon::log('Spawning worker-replacer for reloaded worker #' . $spawnId . '.');
			
				$this->spawnWorkers();
				$this->collections['workers']->threads[$spawnId]->reloaded = true;
			}
		}
	}
	
	/**
	 * Spawn new worker processes
	 * @param $n - integer - number of workers to spawn
	 * @return boolean - success
	 */
	public function spawnWorkers($n = 1) {
		$n = (int) $n;
	
		for ($i = 0; $i < $n; ++$i) {
			$thread = new Daemon_WorkerThread;
			$this->collections['workers']->push($thread);

			if (-1 === $thread->start()) {
				Daemon::log('could not start worker');
			}
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

		foreach ($this->collections['workers']->threads as &$w) {
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
	 * FIXME -> protected?
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
		$this->waitAll($signo);

		if (Daemon::$shm_wstate) {
			shmop_delete(Daemon::$shm_wstate);
		}
		
		file_put_contents(Daemon::$config->pidfile->value,'');
		
		exit(0);
	}
	
	/**
	 * Handler for the SIGCHLD (child is dead) signal in master process.
	 * FIXME +on?
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
	 * FIXME +on ?
	 * @return void
	 */
	protected function sigint() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGINT.');
		}
	
		$this->collections['workers']->signal(SIGINT);
		$this->shutdown(SIGINT);
	}
	
	/**
	 * Handler for the SIGTERM (shutdown) signal in master process
	 * FIXME +on & -> protected?
	 * @return void
	 */
	protected function sigterm() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGTERM.');
		}
	
		$this->collections['workers']->signal(SIGTERM);
		$this->shutdown(SIGTERM);
	}
	
	/**
	 * Handler for the SIGQUIT signal in master process
	 * FIXME +on & -> protected?
	 * @return void
	 */
	protected function sigquit() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGQUIT.');
		}

		$this->collections['workers']->signal(SIGQUIT);
		$this->shutdown(SIGQUIT);
	}

	/**
	 * Handler for the SIGHUP (reload config) signal in master process
	 * FIXME +on & -> protected?
	 * @return void
	 */
	public function sighup() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGHUP (reload config).');
		}

		if (isset(Daemon::$config->configfile)) {
			Daemon::loadConfig(Daemon::$config->configfile->value);
		}

		$this->collections['workers']->signal(SIGHUP);
	}

	/**
	 * Handler for the SIGUSR1 (re-open log-file) signal in master process
	 * FIXME +on & -> protected?
	 * @return void
	 */
	public function sigusr1() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGUSR1 (re-open log-file).');
		}

		Daemon::openLogs();
		$this->collections['workers']->signal(SIGUSR1);
	}

	/**
	 * Handler for the SIGUSR2 (graceful restart all workers) signal in master process
	 * FIXME +on & -> protected?
	 * @return void
	 */
	public function sigusr2() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGUSR2 (graceful restart all workers).');
		}

		$this->collections['workers']->signal(SIGUSR2);
	}

	/**
	 * Handler for the SIGTTIN signal in master process
	 * FIXME not used or -> protected?
	 * @return void
	 */
	public function sigttin() { }

	/**
	 * Handler for the SIGXSFZ signal in master process
	 * FIXME +on & -> protected?
	 * @return void
	 */
	public function sigxfsz() {
		Daemon::log('Master caught SIGXFSZ.');
	}
	
	/**
	 * Handler for non-known signals
	 * FIXME +on & -> protected?
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
