<?php
namespace PHPDaemon\Thread;

use PHPDaemon\Core\AppInstance;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\FS\FileSystem;
use PHPDaemon\Structures\StackCallbacks;

/**
 * Implementation of the worker thread
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class Worker extends Generic {

	/**
	 * Update?
	 * @var boolean
	 */
	public $update = false;

	/**
	 * Reload?
	 * @var boolean
	 */
	public $reload = false;

	/**
	 * Reload time
	 * @var integer
	 */
	protected $reloadTime = 0;

	/**
	 * Reload delay
	 * @var integer
	 */
	protected $reloadDelay = 2;

	/**
	 * Reloaded?
	 * @var boolean
	 */
	public $reloaded = false;

	/**
	 * Time of last activity
	 * @var integer
	 */
	public $timeLastActivity = 0;

	/**
	 * Last time of auto reload
	 * @var integer
	 */
	protected $autoReloadLast = 0;

	/**
	 * Event base
	 * @var EventBase
	 */
	public $eventBase;

	/**
	 * DNS base
	 * @var EventDnsBase
	 */
	public $dnsBase;

	/**
	 * State
	 * @var integer
	 */
	public $state = 0;

	/**
	 * Request counter
	 * @var integer
	 */
	public $reqCounter = 0;

	/**
	 * Break main loop?
	 * @var boolean
	 */
	public $breakMainLoop = false;

	/**
	 * Reload ready?
	 * @var boolean
	 */
	public $reloadReady = false;

	/**
	 * If true, we do not register signals automatically at start
	 * @var boolean
	 */
	protected $delayedSigReg = false;

	/**
	 * Instances count
	 * @var array
	 */
	public $instancesCount = [];

	/**
	 * Connection
	 * @var Connection
	 */
	public $connection;

	/**
	 * Counter GC
	 * @var integer
	 */
	public $counterGC = 0;

	/**
	 * Stack of callbacks to execute
	 * @var \PHPDaemon\Structures\StackCallbacks
	 */
	public $callbacks;

	/** @var \PHPDaemon\IPCManager\IPCManager */
	public $IPCManager;

	/**
	 * Runtime of Worker process.
	 * @return void
	 */
	protected function run() {
		$this->callbacks = new StackCallbacks();
		if (Daemon::$process instanceof Master) {
			Daemon::$process->unregisterSignals();
		}
		if (Daemon::$process && Daemon::$process->eventBase) {
			Daemon::$process->eventBase->reinit();
			$this->eventBase = Daemon::$process->eventBase;
		}
		else {
			$this->eventBase = new \EventBase();
		}
		Daemon::$process = $this;
		if (Daemon::$logpointerAsync) {
			$oldfd                       = Daemon::$logpointerAsync->fd;
			Daemon::$logpointerAsync->fd = null;
			Daemon::$logpointerAsync     = null;
		}
		class_exists('Timer');
		$this->autoReloadLast = time();
		$this->reloadDelay    = Daemon::$config->mpmdelay->value + 2;
		$this->setState(Daemon::WSTATE_PREINIT);

		if (Daemon::$config->autogc->value > 0) {
			gc_enable();
			gc_collect_cycles();
		}
		else {
			gc_disable();
		}

		if (Daemon::$runworkerMode) {
			if (!Daemon::$config->verbosetty->value) {
				fclose(STDIN);
				fclose(STDOUT);
				fclose(STDERR);
			}

			Daemon::$appResolver = require Daemon::$appResolverPath;
			Daemon::$appResolver->preload(true);
		}

		$this->prepareSystemEnv();
		$this->overrideNativeFuncs();

		$this->setState(Daemon::WSTATE_INIT);;
		$this->dnsBase = new \EventDnsBase($this->eventBase, false); // @TODO: test with true
		$this->registerEventSignals();

		FileSystem::init();
		FileSystem::initEvent();
		Daemon::openLogs();

		$this->IPCManager = Daemon::$appResolver->getInstanceByAppName('\PHPDaemon\IPCManager\IPCManager');
		if (!$this->IPCManager) {
			$this->log('cannot instantiate IPCManager');
		}

		Daemon::$appResolver->preload();

		foreach (Daemon::$appInstances as $app) {
			foreach ($app as $appInstance) {
				if (!$appInstance->ready) {
					$appInstance->ready = true;
					$appInstance->onReady();
				}
			}
		}

		$this->setState(Daemon::WSTATE_IDLE);

		Timer::add(function ($event) {

			if (!Daemon::$runworkerMode) {
				if ($this->IPCManager) {
					$this->IPCManager->ensureConnection();
				}
			}

			$this->breakMainLoopCheck();
			if ($this->breakMainLoop) {
				$this->eventBase->exit();
				return;
			}

			if (Daemon::checkAutoGC()) {
				$this->callbacks->push(function ($thread) {
					gc_collect_cycles();
				});
				$this->eventBase->exit();
			}

			$event->timeout();
		}, 1e6 * 1, 'breakMainLoopCheck');
		if (Daemon::$config->autoreload->value > 0) {
			Timer::add(function ($event) {
				static $n = 0;
				$list = get_included_files();
				$s    = sizeof($list);
				if ($s > $n) {
					$slice = array_map('realpath', array_slice($list, $n));
					Daemon::$process->IPCManager->sendPacket(['op' => 'addIncludedFiles', 'files' => $slice]);
					$n = $s;
				}
				$event->timeout();
			}, 1e6 * Daemon::$config->autoreload->value, 'watchIncludedFiles');
		}

		while (!$this->breakMainLoop) {
			$this->callbacks->executeAll($this);
			if (!$this->eventBase->dispatch()) {
				break;
			}
		}
		$this->shutdown();
	}

	/**
	 * Overrides native PHP functions.
	 * @return void
	 */
	protected function overrideNativeFuncs() {
		if (Daemon::supported(Daemon::SUPPORT_RUNKIT_INTERNAL_MODIFY)) {

			runkit_function_rename('header', 'header_native');

			function header() {
				if (!Daemon::$req || !Daemon::$req instanceof Generic) {
					return false;
				}
				return call_user_func_array([Daemon::$req, 'header'], func_get_args());
			}

			runkit_function_rename('is_uploaded_file', 'is_uploaded_file_native');

			function is_uploaded_file() {
				if (!Daemon::$req || !Daemon::$req instanceof Generic) {
					return false;
				}
				return call_user_func_array([Daemon::$req, 'isUploadedFile'], func_get_args());
			}

			runkit_function_rename('move_uploaded_file', 'move_uploaded_file_native');

			function move_uploaded_file() {
				if (!Daemon::$req || !Daemon::$req instanceof Generic) {
					return false;
				}
				return call_user_func_array([Daemon::$req, 'moveUploadedFile'], func_get_args());
			}

			runkit_function_rename('headers_sent', 'headers_sent_native');

			function headers_sent(&$file, &$line) {
				if (!Daemon::$req || !Daemon::$req instanceof Generic) {
					return false;
				}
				return Daemon::$req->headers_sent($file, $line);
			}

			runkit_function_rename('headers_list', 'headers_list_native');

			function headers_list() {
				if (!Daemon::$req || !Daemon::$req instanceof Generic) {
					return false;
				}
				return Daemon::$req->headers_list();
			}

			runkit_function_rename('setcookie', 'setcookie_native');

			function setcookie() {
				if (!Daemon::$req || !Daemon::$req instanceof Generic) {
					return false;
				}
				return call_user_func_array([Daemon::$req, 'setcookie'], func_get_args());
			}

			runkit_function_rename('register_shutdown_function', 'register_shutdown_function_native');

			function register_shutdown_function($cb) {
				if (!Daemon::$req || !Daemon::$req instanceof Generic) {
					return false;
				}
				return Daemon::$req->registerShutdownFunction($cb);
			}

			runkit_function_copy('create_function', 'create_function_native');
			runkit_function_redefine('create_function', '$arg,$body', 'return __create_function($arg,$body);');

			function __create_function($arg, $body) {
				static $cache = [];
				static $maxCacheSize = 128;
				static $sorter;
				static $window = 32;

				if ($sorter === NULL) {
					$sorter = function ($a, $b) {
						if ($a->hits == $b->hits) {
							return 0;
						}

						return ($a->hits < $b->hits) ? 1 : -1;
					};
				}

				$source = $arg . "\x00" . $body;
				$key    = md5($source, true) . pack('l', crc32($source));

				if (isset($cache[$key])) {
					++$cache[$key][1];

					return $cache[$key][0];
				}

				if (sizeof($cache) >= $maxCacheSize + $window) {
					uasort($cache, $sorter);
					$cache = array_slice($cache, $maxCacheSize);
				}

				$cache[$key] = [$cb = eval('return function(' . $arg . '){' . $body . '};'), 0];
				return $cb;
			}
		}
	}

	/**
	 * Setup settings on start.
	 * @return void
	 */
	protected function prepareSystemEnv() {
		proc_nice(Daemon::$config->workerpriority->value);

		register_shutdown_function(function () {
			$this->shutdown(true);
		});

		$this->setTitle(
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
	}

	/**
	 * Log something
	 * @param string - Message.
	 * @return void
	 */
	public function log($message) {
		Daemon::log('W#' . $this->pid . ' ' . $message);
	}

	/**
	 * Reloads additional config-files on-the-fly.
	 * @return void
	 */
	protected function update() {
		FileSystem::updateConfig();
		foreach (Daemon::$appInstances as $app) {
			foreach ($app as $appInstance) {
				$appInstance->handleStatus(AppInstance::EVENT_CONFIG_UPDATED);
			}
		}
	}

	/**
	 * Check if we should break main loop
	 * @return void
	 */
	protected function breakMainLoopCheck() {
		$time = microtime(true);

		if ($this->terminated || $this->breakMainLoop) {
			return;
		}

		if ($this->shutdown) {
			$this->breakMainLoop = true;
			return;
		}

		if ($this->reload) {
			if ($time > $this->reloadTime) {
				$this->breakMainLoop = true;
			}
			return;
		}

		if (
				(Daemon::$config->maxmemoryusage->value > 0)
				&& (memory_get_usage(TRUE) > Daemon::$config->maxmemoryusage->value)
		) {
			$this->log('\'maxmemory\' exceed. Graceful shutdown.');

			$this->initReload();
		}

		if (
				(Daemon::$config->maxrequests->value > 0)
				&& ($this->reqCounter >= Daemon::$config->maxrequests->value)
		) {
			$this->log('\'max-requests\' exceed. Graceful shutdown.');

			$this->initReload();
		}

		if (
				Daemon::$config->maxidle->value
				&& $this->timeLastActivity
				&& ($time - $this->timeLastActivity > Daemon::$config->maxidle->value)
		) {
			$this->log('\'maxworkeridle\' exceed. Graceful shutdown.');

			$this->initReload();
		}

		if ($this->update === true) {
			$this->update = false;
			$this->update();
		}
	}

	/**
	 * Start reloading procedure
	 * @var Connection
	 */
	protected function initReload() {
		$this->reload     = true;
		$this->reloadTime = microtime(true) + $this->reloadDelay;
		$this->setState($this->state);
	}

	/**
	 * Asks the running applications the whether we can go to shutdown current (old) worker.
	 * @return boolean - Ready?
	 */
	protected function appInstancesReloadReady() {
		$ready = true;

		foreach (Daemon::$appInstances as $k => $app) {
			foreach ($app as $name => $appInstance) {
				if (!$appInstance->handleStatus(AppInstance::EVENT_GRACEFUL_SHUTDOWN)) {
					$this->log(__METHOD__ . ': waiting for ' . $k . ':' . $name);
					$ready = false;
				}
			}
		}
		return $ready;
	}

	/**
	 * Shutdown this worker
	 * @param boolean Hard? If hard, we shouldn't wait for graceful shutdown of the running applications.
	 * @return boolean Ready?
	 */
	protected function shutdown($hard = false) {
		$error = error_get_last();
		if ($error) {
			if ($error['type'] === E_ERROR) {
				Daemon::log('W#' . $this->pid . ' crashed by error \'' . $error['message'] . '\' at ' . $error['file'] . ':' . $error['line']);
			}

		}
		if (Daemon::$config->logevents->value) {
			$this->log('event shutdown(' . ($hard ? 'HARD' : '') . ') invoked.');
		}

		if (Daemon::$config->throwexceptiononshutdown->value) {
			throw new \Exception('event shutdown');
		}

		@ob_flush();

		if ($this->terminated === TRUE) {
			if ($hard) {
				exit(0);
			}

			return;
		}

		$this->terminated = TRUE;

		if ($hard) {
			$this->setState(Daemon::WSTATE_SHUTDOWN);
			exit(0);
		}

		$this->reloadReady = $this->appInstancesReloadReady();

		if ($this->reload === TRUE) {
			$this->reloadReady = $this->reloadReady && (microtime(TRUE) > $this->reloadTime);
		}

		if (Daemon::$config->logevents->value) {
			$this->log('reloadReady = ' . Debug::dump($this->reloadReady));
		}

		unset(Timer::$list['breakMainLoopCheck']);

		Timer::add(function ($event) {
			$self = Daemon::$process;

			$self->reloadReady = $self->appInstancesReloadReady();

			if ($self->reload === TRUE) {
				$self->reloadReady = $self->reloadReady && (microtime(TRUE) > $self->reloadTime);
			}
			if (!$self->reloadReady) {
				$event->timeout();
			}
			else {
				$self->eventBase->exit();
			}
		}, 1e6, 'checkReloadReady');
		while (!$this->reloadReady) {
			$this->eventBase->loop();
		}
		FileSystem::waitAllEvents(); // ensure that all I/O events completed before suicide
		exit(0); // R.I.P.
	}

	/**
	 * Set current status of worker
	 * @param int Constant
	 * @return boolean Success.
	 */
	public function setState($int) {
		if (Daemon::$compatMode) {
			return true;
		}
		if (!$this->id) {
			return false;
		}
		if ($int === $this->state) {
			return false;
		}

		if (Daemon::$config->logworkersetstate->value) {
			$this->log('state is ' . Daemon::$wstateRev[$int]);
		}

		$this->state = $int;

		if ($this->reload) {
			$int += 100;
		}
		Daemon::$shm_wstate->write(chr($int), $this->id - 1);
		return true;
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

		$this->breakMainLoop = true;
		$this->eventBase->exit();
	}

	/**
	 * Handler of the SIGQUIT (graceful shutdown) signal in worker process.
	 * @return void
	 */
	protected function sigquit() {
		if (Daemon::$config->logsignals->value) {
			$this->log('caught SIGQUIT.');
		}

		parent::sigquit();
	}

	/**
	 * Handler of the SIGHUP (reload config) signal in worker process.
	 * @return void
	 */
	protected function sighup() {
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
	protected function sigusr1() {
		if (Daemon::$config->logsignals->value) {
			$this->log('caught SIGUSR1 (re-open log-file).');
		}

		Daemon::openLogs();
	}

	/**
	 * Handler of the SIGUSR2 (graceful shutdown for update) signal in worker process.
	 * @return void
	 */
	protected function sigusr2() {
		if (Daemon::$config->logsignals->value) {
			$this->log('caught SIGUSR2 (graceful shutdown for update).');
		}

		$this->gracefulRestart();
	}

	/**
	 * Graceful restart
	 * @return void
	 */
	public function gracefulRestart() {
		$this->reload     = true;
		$this->reloadTime = microtime(true) + $this->reloadDelay;
		$this->setState($this->state);
	}

	/**
	 * Handler of the SIGTTIN signal in worker process.
	 * @return void
	 */
	protected function sigttin() {
	}

	/**
	 * Handler of the SIGPIPE signal in worker process.
	 * @return void
	 */
	protected function sigpipe() {
	}

	/**
	 * Handler of non-known signals.
	 * @return void
	 */
	protected function sigunknown($signo) {
		if (isset(Generic::$signals[$signo])) {
			$sig = Generic::$signals[$signo];
		}
		else {
			$sig = 'UNKNOWN';
		}

		$this->log('caught signal #' . $signo . ' (' . $sig . ').');
	}

	/**
	 * Destructor of worker thread.
	 * @return void
	 */
	public function __destruct() {
		if (posix_getpid() === $this->pid) {
			$this->setState(Daemon::WSTATE_SHUTDOWN);
		}
	}
}
