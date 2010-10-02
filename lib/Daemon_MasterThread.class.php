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
	 * @method run
	 * @description Runtime of Master process.
	 * @return void
	 */
	public function run() {
		proc_nice(Daemon::$config->masterpriority->value);
		gc_enable();
		register_shutdown_function(array($this,'onShutdown'));
		$this->collections = array('workers' => new ThreadCollection);

		$this->setproctitle(
			Daemon::$runName . ': master process' 
			. (Daemon::$config->pidfile->value !== Daemon::$config->pidfile->defaultValue ? ' (' . Daemon::$config->pidfile->value . ')' : '')
		);

		Daemon::$appResolver = require Daemon::$config->path->value;
		Daemon::$appResolver->preloadPrivileged(); 

		$this->spawnWorkers(min(
			Daemon::$config->startworkers->value,
			Daemon::$config->maxworkers->value
		));
		$mpmLast = time();
		$autoReloadLast = time();
		$c = 1;

		while (TRUE) {
			pcntl_signal_dispatch();
			$this->sigwait(1,0);
			clearstatcache();

			if (Daemon::$logpointerpath !== Daemon::parseStoragepath(Daemon::$config->logstorage->value)) { 
				$this->sigusr1();
			}
      
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
	
			if (time() > $mpmLast+Daemon::$parsedSettings['mpmdelay']) {
				$mpmLast = time();
				++$c;
				
				if ($c > 0xFFFFF) {
					$c = 0;
				}
				
				if (($c % 10 == 0)) {
					$this->collections['workers']->removeTerminated(TRUE);
					gc_collect_cycles();
				} else {
					$this->collections['workers']->removeTerminated();
				}
				
				/* FIXME mpm function in config
				if (
					isset(Daemon::$config->mpm) 
					&& is_callable($c = Daemon::$config->mpm)
				) {
					call_user_func($c);
				} else {
					// default MPM
					$state = Daemon::getStateOfWorkers($this);
					
					if ($state) {
						$n = max(
							min(
								Daemon::$config->minspareworkers - $state['idle'], 
								Daemon::$config->maxworkers - $state['alive']
							),
							Daemon::$config->minworkers - $state['alive']
						);

						if ($n > 0) {
							Daemon::log('Spawning ' . $n . ' worker(s).');
							$this->spawnWorkers($n);
						}

						$n = min(
							$state['idle'] - Daemon::$config->maxspareworkers,
							$state['alive'] - Daemon::$config->minworkers
						);
						
						if ($n > 0) {
							Daemon::log('Stopping ' . $n . ' worker(s).');
							$this->stopWorkers($n);
						}
					}
				} */
			}
		}
	}
	
	public function reloadWorker($spawnId) {
		if (isset($this->collections['workers']->threads[$spawnId])) {
			if (!$this->collections['workers']->threads[$spawnId]->reloaded) {
				Daemon::log('Spawning worker-replacer for reloaded worker #' . $spawnId . '.');
			
				$this->spawnWorkers();
				$this->collections['workers']->threads[$spawnId]->reloaded = TRUE;
			}
		}
	}
	
	/**
	 * @method spawnWorkers
	 * @param $n - integer - number of workers to spawn
	 * @description spawn new workers processes.
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

		return TRUE;
	}

	/**
	 * @method stopWorkers
	 * @param $n - integer - number of workers to stop
	 * @description stop the workers.
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
		
		return TRUE;
	}
	
	/**
	 * @method onShutdown
	 * @description Called when master is going to shutdown.
	 * @return void
	 */
	public function onShutdown() {
		if ($this->pid != posix_getpid()) {
			return;
		}

		if ($this->shutdown === TRUE) {
			return;
		}

		Daemon::log('Unexcepted master shutdown.'); 

		$this->shutdown(SIGTERM);
	}

	/**
	 * @method shutdown
	 * @param integer System singal's number.
	 * @description Called when master is going to shutdown.
	 * @return void
	 */
	public function shutdown($signo = FALSE) {
		$this->shutdown = TRUE;
		$this->waitAll($signo);

		if (Daemon::$shm_wstate) {
			shmop_delete(Daemon::$shm_wstate);
		}
		
		file_put_contents(Daemon_Bootstrap::$pidfile,'');
		
		exit(0);
	}
	
	/**
	 * @method sigchld
	 * @description Handler of the SIGCHLD (child is dead) signal in master process.
	 * @return void
	 */
	protected function sigchld() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGCHLD.');
		}

		parent::sigchld();
	}

	/**
	 * @method sigint
	 * @description Handler of the SIGINT (shutdown) signal in master process. Shutdown.
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
	 * @method sigterm
	 * @description Handler of the SIGTERM (shutdown) signal in master process.
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
	 * @method sigquit
	 * @description Handler of the SIGQUIT signal in master process.
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
	 * @method sighup
	 * @description Handler of the SIGHUP (reload config) signal in master process.
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
	 * @method sigusr1
	 * @description Handler of the SIGUSR1 (re-open log-file) signal in master process.
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
	 * @method sigusr2
	 * @description Handler of the SIGUSR2 (graceful restart all workers) signal in master process.
	 * @return void
	 */
	public function sigusr2() {
		if (Daemon::$config->logsignals->value) {
			Daemon::log('Master caught SIGUSR2 (graceful restart all workers).');
		}

		$this->collections['workers']->signal(SIGUSR2);
	}

	/**
	 * @method sigttin
	 * @description Handler of the SIGTTIN signal in master process.
	 * @return void
	 */
	public function sigttin() { }

	/**
	 * @method sigxfsz
	 * @description Handler of the SIGXSFZ signal in master process.
	 * @return void
	 */
	public function sigxfsz() {
		Daemon::log('Master caught SIGXFSZ.');
	}
	
	/**
	 * @method sigunknown
	 * @description Handler of non-known signals.
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
