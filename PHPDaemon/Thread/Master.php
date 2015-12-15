<?php
namespace PHPDaemon\Thread;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\FS\FileSystem;
use PHPDaemon\Structures\StackCallbacks;
use PHPDaemon\Thread\Collection;
use PHPDaemon\Thread\Generic;
use PHPDaemon\Thread\IPC;

/**
 * Implementation of the master thread
 *
 * @package Core
 *
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class Master extends Generic {

	/** @var bool */
	public $delayedSigReg = true;
	/** @var bool */
	public $breakMainLoop = false;
	/** @var bool */
	public $reload = false;
	/** @var int */
	public $connCounter = 0;
	/** @var StackCallbacks */
	public $callbacks;
	/** @var Collection */
	public $workers;
	/** @var Collection */
	public $ipcthreads;
	/** @var EventBase */
	public $eventBase;
	/** @var */
	public $eventBaseConfig;
	/** @var */
	public $lastMpmActionTs;
	/** @var int */
	public $minMpmActionInterval = 1; // in seconds
	private $timerCb;

	/**
	 * Runtime of Master process
	 * @return void
	 */
	protected function run() {

		Daemon::$process = $this;

		$this->prepareSystemEnv();
		class_exists('Timer'); // ensure loading this class
		gc_enable();

		/* This line must be commented according to current libevent binding implementation. May be uncommented in future. */
		//$this->eventBase = new \EventBase; 

		if ($this->eventBase) {
			$this->registerEventSignals();
		}
		else {
			$this->registerSignals();
		}

		$this->workers                   = new Collection();
		$this->collections['workers']    = $this->workers;
		$this->ipcthreads                = new Collection;
		$this->collections['ipcthreads'] = $this->ipcthreads;

		Daemon::$appResolver->preload(true);

		$this->callbacks = new StackCallbacks();
		$this->spawnIPCThread();
		$this->spawnWorkers(min(
								Daemon::$config->startworkers->value,
								Daemon::$config->maxworkers->value
							));
		$this->timerCb = function ($event) use (&$cbs) {
			static $c = 0;

			++$c;

			if ($c > 0xFFFFF) {
				$c = 1;
			}

			if (($c % 10 == 0)) {
				gc_collect_cycles();
			}

			if (!$this->lastMpmActionTs || ((microtime(true) - $this->lastMpmActionTs) > $this->minMpmActionInterval)) {
				$this->callMPM();
			}
			if ($event) {
				$event->timeout();
			}
		};

		if ($this->eventBase) { // we are using libevent in Master
			Timer::add($this->timerCb, 1e6 * Daemon::$config->mpmdelay->value, 'MPM');
			while (!$this->breakMainLoop) {
				$this->callbacks->executeAll($this);
				if (!$this->eventBase->dispatch()) {
					break;
				}
			}
		}
		else { // we are NOT using libevent in Master
			$lastTimerCall = microtime(true);
			while (!$this->breakMainLoop) {
				$this->callbacks->executeAll($this);
				if (microtime(true) > $lastTimerCall + Daemon::$config->mpmdelay->value) {
					call_user_func($this->timerCb, null);
					$lastTimerCall = microtime(true);
				}
				$this->sigwait();
			}
		}
	}

	/**
	 * Log something
	 * @param string - Message.
	 * @param string $message
	 * @return void
	 */
	public function log($message) {
		Daemon::log('M#' . $this->pid . ' ' . $message);
	}

	/**
	 * @return int
	 */
	protected function callMPM() {
		$state = Daemon::getStateOfWorkers($this);
		if (isset(Daemon::$config->mpm->value) && is_callable(Daemon::$config->mpm->value)) {
			return call_user_func(Daemon::$config->mpm->value, $this, $state);
		}

		$upToMinWorkers      = Daemon::$config->minworkers->value - $state['alive'];
		$upToMaxWorkers      = Daemon::$config->maxworkers->value - $state['alive'];
		$upToMinSpareWorkers = Daemon::$config->minspareworkers->value - $state['idle'];
		if ($upToMinSpareWorkers > $upToMaxWorkers) {
			$upToMinSpareWorkers = $upToMaxWorkers;
		}
		$n = max($upToMinSpareWorkers, $upToMinWorkers);
		if ($n > 0) {
			//Daemon::log('minspareworkers = '.Daemon::$config->minspareworkers->value);
			//Daemon::log('maxworkers = '.Daemon::$config->maxworkers->value);
			//Daemon::log('maxspareworkers = '.Daemon::$config->maxspareworkers->value);
			//Daemon::log(json_encode($state));
			//Daemon::log('upToMinSpareWorkers = ' . $upToMinSpareWorkers . '   upToMinWorkers = ' . $upToMinWorkers);
			Daemon::log('Spawning ' . $n . ' worker(s)');
			$this->spawnWorkers($n);
			return $n;
		}

		$a = ['default' => 0];
		if (Daemon::$config->maxspareworkers->value > 0) {
			// if MaxSpareWorkers enabled, we have to stop idle workers, keeping in mind the MinWorkers
			$a['downToMaxSpareWorkers'] = min(
				$state['idle'] - Daemon::$config->maxspareworkers->value, // downToMaxSpareWorkers
				$state['alive'] - Daemon::$config->minworkers->value //downToMinWorkers
			);
		}
		$a['downToMaxWorkers'] = $state['alive'] - $state['reloading'] - Daemon::$config->maxworkers->value;
		$n                     = max($a);
		if ($n > 0) {
			//Daemon::log('down = ' . json_encode($a));
			//Daemon::log(json_encode($state));
			Daemon::log('Stopping ' . $n . ' worker(s)');
			$this->stopWorkers($n);
			return -$n;
		}
		return 0;
	}

	/**
	 * Setup settings on start.
	 * @return void
	 */
	protected function prepareSystemEnv() {
		register_shutdown_function(function () {
			if ($this->pid != posix_getpid()) {
				return;
			}
			if ($this->shutdown === true) {
				return;
			}
			$this->log('Unexcepted shutdown.');
			$this->shutdown(SIGTERM);
		});

		posix_setsid();
		proc_nice(Daemon::$config->masterpriority->value);
		if (!Daemon::$config->verbosetty->value) {
			fclose(STDIN);
			fclose(STDOUT);
			fclose(STDERR);
		}

		$this->setTitle(
			Daemon::$runName . ': master process'
			. (Daemon::$config->pidfile->value !== Daemon::$config->pidfile->defaultValue ? ' (' . Daemon::$config->pidfile->value . ')' : '')
		);
	}

	/**
	 * Reload worker by internal id
	 * @param integer - Id of worker
	 * @param integer $id
	 * @return void
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
	protected function spawnWorkers($n) {
		if (FileSystem::$supported) {
			eio_event_loop();
		}
		$n = (int)$n;

		for ($i = 0; $i < $n; ++$i) {
			$thread = new Worker;
			$this->workers->push($thread);
			$this->callbacks->push(function ($self) use ($thread) {
				// @check - is it possible to run iterate of main event loop without child termination?
				$thread->start();
				$pid = $thread->getPid();
				if ($pid < 0) {
					Daemon::$process->log('could not fork worker');
				}
				elseif ($pid === 0) { // worker
					Daemon::log('Unexcepted execution return to outside of Thread->start()');
					exit;
				}
			});

		}
		if ($n > 0) {
			$this->lastMpmActionTs = microtime(true);
			if ($this->eventBase) {
				$this->eventBase->stop();
			}
		}
		return true;
	}

	/**
	 * Spawn IPC process
	 * @param $n - integer - number of workers to spawn
	 * @return boolean - success
	 */
	protected function spawnIPCThread() {
		if (FileSystem::$supported) {
			eio_event_loop();
		}
		$thread = new IPC;
		$this->ipcthreads->push($thread);

		$this->callbacks->push(function ($self) use ($thread) {
			$thread->start();
			$pid = $thread->getPid();
			if ($pid < 0) {
				Daemon::$process->log('could not fork IPCThread');
			}
			elseif ($pid === 0) { // worker
				$this->log('Unexcepted execution return to outside of Thread->start()');
				exit;
			}
		});
		if ($this->eventBase) {
			$this->eventBase->stop();
		}
		return true;
	}

	/**
	 * Stop the workers
	 * @param $n - integer - number of workers to stop
	 * @return boolean - success
	 */
	protected function stopWorkers($n = 1) {

		Daemon::log('--'.$n.'-- '.Debug::backtrace().'-----');

		$n = (int)$n;
		$i = 0;

		foreach ($this->workers->threads as &$w) {
			if ($i >= $n) {
				break;
			}

			if ($w->shutdown) {
				continue;
			}

			if ($w->reloaded) {
				continue;
			}

			$w->stop();
			++$i;
		}

		$this->lastMpmActionTs = microtime(true);
		return true;
	}

	/**
	 * Called when master is going to shutdown
	 * @param integer System singal's number
	 * @return void
	 */
	protected function shutdown($signo = false) {
		$this->shutdown = true;
		$this->waitAll(true);
		Daemon::$shm_wstate->delete();
		file_put_contents(Daemon::$config->pidfile->value, '');
		exit(0);
	}

	/**
	 * Handler for the SIGCHLD (child is dead) signal in master process.
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
	 * @return void
	 */
	protected function sigint() {
		if (Daemon::$config->logsignals->value) {
			$this->log('Caught SIGINT.');
		}

		$this->signalToChildren(SIGINT);
		$this->shutdown(SIGINT);
	}

	/**
	 * @param $signo
	 */
	public function signalToChildren($signo) {
		foreach ($this->collections as $col) {
			$col->signal($signo);
		}
	}

	/**
	 * Handler for the SIGTERM (shutdown) signal in master process
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
	 * Handler for the SIGTSTP (graceful stop all workers) signal in master process
	 * @return void
	 */
	protected function sigtstp() {
		if (Daemon::$config->logsignals->value) {
			$this->log('Caught SIGTSTP (graceful stop all workers).');
		}

		$this->signalToChildren(SIGTSTP);
		$this->shutdown(SIGTSTP);
	}

	/**
	 * Handler for the SIGHUP (reload config) signal in master process
	 * @return void
	 */
	protected function sighup() {
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
	 * @return void
	 */
	protected function sigusr1() {
		if (Daemon::$config->logsignals->value) {
			$this->log('Caught SIGUSR1 (re-open log-file).');
		}

		Daemon::openLogs();
		$this->signalToChildren(SIGUSR1);
	}

	/**
	 * Handler for the SIGUSR2 (graceful restart all workers) signal in master process
	 * @return void
	 */
	protected function sigusr2() {
		if (Daemon::$config->logsignals->value) {
			$this->log('Caught SIGUSR2 (graceful restart all workers).');
		}
		$this->signalToChildren(SIGUSR2);
	}

	/**
	 * Handler for the SIGTTIN signal in master process
	 * Used as "ping" signal
	 * @return void
	 */
	protected function sigttin() {
	}

	/**
	 * Handler for the SIGXSFZ signal in master process
	 * @return void
	 */
	protected function sigxfsz() {
		$this->log('Caught SIGXFSZ.');
	}

	/**
	 * Handler for non-known signals
	 * @return void
	 */
	protected function sigunknown($signo) {
		if (isset(Generic::$signals[$signo])) {
			$sig = Generic::$signals[$signo];
		}
		else {
			$sig = 'UNKNOWN';
		}
		$this->log('Caught signal #' . $signo . ' (' . $sig . ').');
	}
}
