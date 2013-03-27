<?php

/**
 * Implementation of the IPC thread
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
// @TODO: respawning IPCThread on unexpected failures
class Daemon_IPCThread extends Thread {
	/**
	 * Event base
	 * @var EventBase
	 */
	public $eventBase;

	/**
	 * Break main loop?
	 * @var boolean
	 */
	protected $breakMainLoop = false;

	/**
	 * Reload ready?
	 * @var boolean
	 */
	protected $reloadReady = false;

	/**
	 * If true, we do not register signals automatically at start
	 * @var boolean
	 */
	protected $delayedSigReg = true;

	/**
	 * Instances count
	 * @var hash
	 */
	public $instancesCount = [];
	
	/**
	 * File watcher
	 * @var FileWatcher
	 */
	public $fileWatcher;

	/**
	 * If true, we do not register signals automatically at start
	 * @var boolean
	 */
	public $reload = false;
	
	/**
	 * Runtime of Worker process.
	 * @return void
	 */
	protected function run() {
		if (Daemon::$process instanceof Daemon_MasterThread) {
			Daemon::$process->unregisterSignals();
		}
		if (Daemon::$process->eventBase) {
			Daemon::$process->eventBase->reinit();
			$this->eventBase = Daemon::$process->eventBase;
		} else {
			$this->eventBase = new EventBase();
		}
		Daemon::$process = $this;
		if (Daemon::$logpointerAsync) {
			$oldfd = Daemon::$logpointerAsync->fd;
			Daemon::$logpointerAsync->fd = null;
			Daemon::$logpointerAsync = null;
		}
		class_exists('Timer');

		if (Daemon::$config->autogc->value > 0) {
			gc_enable();
		} else {
			gc_disable();
		}
		$this->prepareSystemEnv();
		$this->registerEventSignals();
		FS::init(); // re-init
		FS::initEvent();
		Daemon::openLogs();

		$this->fileWatcher = new FileWatcher;
		$this->IPCManager = Daemon::$appResolver->getInstanceByAppName('IPCManager');
		
		while (!$this->breakMainLoop) {
			if (!$this->eventBase->dispatch()) {
				break;
			}
		}
	}

	/**
	 * Setup settings on start.
	 * @return void
	 */
	protected function prepareSystemEnv() {
		proc_nice(Daemon::$config->ipcthreadpriority->value);
		register_shutdown_function(array($this,'shutdown'));
		
		$this->setTitle(
			Daemon::$runName . ': IPC process'
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
				$this->log('You must have the root privileges to change root.');
				exit(0);
			}
			elseif (!chroot(Daemon::$config->chroot->value)) {
				Daemon::log('Couldn\'t change root to \'' . Daemon::$config->chroot->value . '\'.');
				exit(0);
			}
		}

		if (isset(Daemon::$config->group->value)) {
			if ($sg === false) {
				$this->log('Couldn\'t change group to \'' . Daemon::$config->group->value . '\'. You must replace config-variable \'group\' with existing group.');
				exit(0);
			}
			elseif (
				($sg['gid'] != posix_getgid())
				&& (!posix_setgid($sg['gid']))
			) {
				$this->log('Couldn\'t change group to \'' . Daemon::$config->group->value . "'. Error (" . ($errno = posix_get_last_error()) . '): ' . posix_strerror($errno));
				exit(0);
			}
		}

		if (isset(Daemon::$config->user->value)) {
			if ($su === false) {
				$this->log('Couldn\'t change user to \'' . Daemon::$config->user->value . '\', user not found. You must replace config-variable \'user\' with existing username.');
				exit(0);
			}
			elseif (
				($su['uid'] != posix_getuid())
				&& (!posix_setuid($su['uid']))
			) {
				$this->log('Couldn\'t change user to \'' . Daemon::$config->user->value . "'. Error (" . ($errno = posix_get_last_error()) . '): ' . posix_strerror($errno));
				exit(0);
			}
		}

		if (Daemon::$config->cwd->value !== '.') {
			if (!@chdir(Daemon::$config->cwd->value)) {
				$this->log('Couldn\'t change directory to \'' . Daemon::$config->cwd->value . '.');
			}
		}
	}

	/**
	 * Log something
	 * @param string - Message.
	 * @return void
	 */
	public function log($message) {
		Daemon::log('I#' . $this->pid . ' ' . $message);
	}

	/**
	 * Reloads additional config-files on-the-fly.
	 * @return void
	 */
	protected function update() {
		FS::updateConfig();
		foreach (Daemon::$appInstances as $app) {
			foreach ($app as $appInstance) {
				$appInstance->handleStatus(AppInstance::EVENT_CONFIG_UPDATED);
			}
		}
	}

	/**
	 * Shutdown thread
	 * @param boolean - Hard? If hard, we shouldn't wait for graceful shutdown of the running applications.
	 * @return boolean - Ready?
	 */
	public function shutdown($hard = false) {
		$error = error_get_last(); 
		if ($error) {
			if ($error['type'] === E_ERROR) {
				$this->log('crashed by error \''.$error['message'].'\' at '.$error['file'].':'.$error['line']);
			}

		}
		if (Daemon::$config->logevents->value) {
			$this->log('event shutdown(' . ($hard ? 'HARD' : '') . ') invoked.');
		}

		if (Daemon::$config->throwexceptiononshutdown->value) {
			throw new Exception('event shutdown');
		}

		@ob_flush();

		if ($this->terminated === true) {
			if ($hard) {
				exit(0);
			}

			return;
		}

		$this->terminated = true;
		if ($hard) {
			exit(0);
		}
		FS::waitAllEvents(); // ensure that all I/O events completed before suicide
		posix_kill(posix_getppid(), SIGCHLD); // praying to Master
		exit(0); // R.I.P.
	}

	/**
	 * Handler of the SIGINT (hard shutdown) signal in worker process.
	 * @return void
	 */
	protected function sigint() {
		if (Daemon::$config->logsignals->value) {
			$this->log('caught SIGINT.');
		}

		$this->shutdown(true);
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

		$this->update = true;
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
	}

	/**
	 * Handler of the SIGTTIN signal in worker process.
	 * @return void
	 */
	public function sigttin() {}

	/**
	 * Handler of the SIGXSFZ signal in worker process.
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
}
