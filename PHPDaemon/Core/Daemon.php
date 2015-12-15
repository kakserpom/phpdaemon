<?php
namespace PHPDaemon\Core;

use PHPDaemon\Config;
use PHPDaemon\FS\File;
use PHPDaemon\FS\FileSystem;
use PHPDaemon\Thread;
use PHPDaemon\Thread\Collection;
use PHPDaemon\Utils\ShmEntity;

/**
 * Daemon "namespace"
 *
 * @package Core
 *
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class Daemon {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * Support of runkit sandbox functionality
	 * @var integer
	 */
	const SUPPORT_RUNKIT_SANDBOX = 0;

	/**
	 * Support of runkit on-the-fly userland code modification functionality
	 * @var integer
	 */
	const SUPPORT_RUNKIT_MODIFY = 1;

	/**
	 * Support of runkit on-the-fly internal code modification functionality
	 * @var integer
	 */
	const SUPPORT_RUNKIT_INTERNAL_MODIFY = 2;

	/**
	 * Support of runkit on-the-fly userland code import functionality
	 * @var integer
	 */
	const SUPPORT_RUNKIT_IMPORT = 3;

	/**
	 * Worker state: idle. It means that the worker IS NOT in the middle of execution valuable callback (e.g. request) at this moment of time. Currently, it does not mean that worker not have any pending operations.
	 * @var integer
	 */
	const WSTATE_IDLE = 1;

	/**
	 * Worker state: busy. It means that the worker IS in the middle of execution valuable callback.
	 */
	const WSTATE_BUSY = 2;

	/**
	 * Worker state: shutdown. It means that worker is shutdown. Nothing special.
	 * @var integer
	 */
	const WSTATE_SHUTDOWN = 3;

	/**
	 * Worker state: shutdown. It means that worker is shutdown.
	 * @var integer
	 */
	const WSTATE_PREINIT = 4;

	/**
	 * Worker state: initialization. It means that worker is starting right now.
	 * @var integer
	 */
	const WSTATE_INIT = 5;

	/**
	 * Hash of possible worker states
	 * @var array
	 */
	public static $wstateRev = [
		1 => 'IDLE',
		2 => 'BUSY',
		3 => 'SHUTDOWN',
		4 => 'PREINIT',
		5 => 'INIT',
	];

	/**
	 * Shared memory WSTATE segment size
	 * @var integer
	 */
	const SHM_WSTATE_SIZE = 1024;

	/**
	 * PHPDaemon version
	 * @var string
	 */
	public static $version;

	/**
	 * PHPDaemon start time
	 * @var integer
	 */
	public static $startTime;

	/**
	 * Log file resource
	 * @var resource
	 */
	public static $logpointer;

	/**
	 * Log file async. resource
	 * @var object
	 */
	public static $logpointerAsync;

	/**
	 * Supported things array
	 * @var string
	 */
	protected static $support = [];

	/**
	 * Current thread object
	 * @var \PHPDaemon\Thread\Master|\PHPDaemon\Thread\IPC|\PHPDaemon\Thread\Worker
	 */
	public static $process;

	/**
	 * AppResolver
	 * @var \PHPDaemon\Core\AppResolver
	 */
	public static $appResolver;

	/**
	 * Running application instances
	 * @var array
	 */
	public static $appInstances = [];

	/**
	 * Running request
	 * @var \PHPDaemon\Request\Generic
	 */
	public static $req;

	/**
	 * Running context
	 * @var object
	 */
	public static $context;

	/**
	 * Collection of workers
	 * @var \PHPDaemon\Thread\Collection
	 */
	protected static $workers;

	/**
	 * Collection of masters
	 * @var \PHPDaemon\Thread\Collection
	 */
	protected static $masters;

	/**
	 * Copy of $_SERVER on the daemon start
	 * @var array
	 */
	protected static $initservervar;

	/**
	 * Shared memory 'WSTATE' entity
	 * @var \PHPDaemon\Thread\Collection
	 */
	public static $shm_wstate;

	/**
	 * Running under Apache/PHP-FPM in compatibility mode?
	 * @var boolean
	 */
	public static $compatMode = false;

	/**
	 * Base name of daemon instance
	 * @var string
	 */
	public static $runName = 'phpdaemon';

	/**
	 * Configuration object
	 * @var \PHPDaemon\Config\Object
	 */
	public static $config;

	/**
	 * Path to application resolver
	 * @var string
	 */
	public static $appResolverPath;


	/**
	 * Restrict error control. When true, operator '@' means nothing.
	 * @var boolean
	 */
	public static $restrictErrorControl = false;

	/**
	 * Default error reporting level
	 * @var integer
	 */
	public static $defaultErrorLevel;

	/**
	 * Is it running under master-less 'runworker' mode?
	 * @var bool
	 */
	public static $runworkerMode = false;

	/**
	 * Whether if the current execution stack contains ob-filter
	 * @var bool
	 */
	public static $obInStack = false;

	/**
	 * Mechanism of catching errors. Set it to true, then run your suspect code, and then check this property again. If false, there was error message.
	 * @var bool
	 */
	public static $noError = false;

	/**
	 * Loads default setting.
	 * @return void
	 */
	public static function initSettings() {
		Daemon::$version = file_get_contents('VERSION', true);

		Daemon::$config = new Config\Object;

		if (!defined('SO_REUSEPORT') && strpos(php_uname('s'), 'BSD') !== false) {
			// @TODO: better if-BSD check
			define('SO_REUSEPORT', 0x200);
		} 
	}

	/**
	 * Glob function with support of include_path
	 * @param string $pattern
	 * @param int $flags
	 * @return array
	 */
	public static function glob($pattern, $flags = 0) {
		$r = [];
		foreach (explode(':', get_include_path()) as $path) {
			$r = array_merge($r, glob($p = $path . '/' . $pattern, $flags));
		}
		return array_unique($r);
	}

	/**
	 * Generate a unique ID.
	 * @return string Returns the unique identifier, as a string.
	 */
	public static function uniqid() {
		static $n = 0;
		return str_shuffle(md5(str_shuffle(
								   microtime(true) . chr(mt_rand(0, 0xFF))
								   . Daemon::$process->getPid() . chr(mt_rand(0, 0xFF))
								   . (++$n) . mt_rand(0, mt_getrandmax()))));
	}

	/**
	 * Load PHP extension (module) if absent
	 * @param string $mod
	 * @param string $version
	 * @param string $compare
	 * @return bool $success
	 */
	public static function loadModuleIfAbsent($mod, $version = null, $compare = '>=') {
		if (!extension_loaded($mod)) {
			if (!get_cfg_var('enable_dl')) {
				return false;
			}
			if (!@dl(basename($mod) . '.so')) {
				return false;
			}
		}
		if (!$version) {
			return true;
		}
		try {
			$ext = new \ReflectionExtension($mod);
			return version_compare($ext->getVersion(), $version, $compare);
		} catch (\ReflectionException $e) {
			return false;
		}
	}

	/**
	 * Call automatic garbage collector
	 * @return void
	 */
	public static function callAutoGC() {
		if (self::checkAutoGC()) {
			gc_collect_cycles();
		}
	}

	/**
	 * Check if we need to run automatic garbage collector
	 * @return bool
	 */
	public static function checkAutoGC() {
		if (
				(Daemon::$config->autogc->value > 0)
				&& (Daemon::$process->counterGC > 0)
				&& (Daemon::$process->counterGC >= Daemon::$config->autogc->value)
		) {
			Daemon::$process->counterGC = 0;
			return true;
		}
		return false;
	}

	/**
	 * Output filter
	 * @return string Output
	 */
	public static function outputFilter($s) {
		static $n = 0; // recursion counter

		if ($s === '') {
			return '';
		}
		++$n;
		Daemon::$obInStack = true;
		if (Daemon::$config->obfilterauto->value && Daemon::$context instanceof \PHPDaemon\Request\Generic) {
			Daemon::$context->out($s, false);
		}
		else {
			Daemon::log('Unexcepted output (len. ' . strlen($s) . '): \'' . $s . '\'');
		}
		--$n;
		Daemon::$obInStack = $n > 0;
		return '';
	}

	/**
	 * Uncaught exception handler
	 * @param \Exception $e
	 * @return void
	 */
	public static function uncaughtExceptionHandler(\Exception $e) {
		if (Daemon::$context !== null) {
			if (Daemon::$context->handleException($e)) {
				return;
			}
		}
		$msg = $e->getMessage();
		Daemon::log('Uncaught ' . get_class($e) . ' (' . $e->getCode() . ')' . (strlen($msg) ? ': ' . $msg : '') . ".\n" . $e->getTraceAsString());
		if (Daemon::$context instanceof \PHPDaemon\Request\Generic) {
			Daemon::$context->out('<b>Uncaught ' . get_class($e) . ' (' . $e->getCode() . ')</b>' . (strlen($msg) ? ': ' . $msg : '') . '.<br />');
		}
	}

	/**
	 * Error handler
	 * @param integer $errno
	 * @param string $errstr
	 * @param string $errfile
	 * @param integer $errline
	 * @param array $errcontext
	 */
	public static function errorHandler($errno, $errstr, $errfile, $errline, $errcontext) {
		Daemon::$noError = false;
		$l = error_reporting();
		if ($l === 0) {
			if (!Daemon::$restrictErrorControl) {
				return;
			}
		}
		elseif (!($l & $errno)) {
			return;
		}

		static $errtypes = [
			E_ERROR             => 'Fatal error',
			E_WARNING           => 'Warning',
			E_PARSE             => 'Parse error',
			E_NOTICE            => 'Notice',
			E_PARSE             => 'Parse error',
			E_USER_ERROR        => 'Fatal error (userland)',
			E_USER_WARNING      => 'Warning (userland)',
			E_USER_NOTICE       => 'Notice (userland)',
			E_STRICT            => 'Notice (userland)',
			E_RECOVERABLE_ERROR => 'Fatal error (recoverable)',
			E_DEPRECATED        => 'Deprecated',
			E_USER_DEPRECATED   => 'Deprecated (userland)',
		];
		$errtype = $errtypes[$errno];
		Daemon::log($errtype . ': ' . $errstr . ' in ' . $errfile . ':' . $errline . "\n" . Debug::backtrace());
		if (Daemon::$context instanceof \PHPDaemon\Request\Generic) {
			Daemon::$context->out('<strong>' . $errtype . '</strong>: ' . $errstr . ' in ' . basename($errfile) . ':' . $errline . '<br />');
		}
	}

	/**
	 * Performs initial actions.
	 * @return void
	 */
	public static function init() {
		Daemon::$startTime = time();
		set_time_limit(0);

		Daemon::$defaultErrorLevel    = error_reporting();
		Daemon::$restrictErrorControl = (bool)Daemon::$config->restricterrorcontrol->value;
		ob_start(['\PHPDaemon\Core\Daemon', 'outputFilter']);
		set_error_handler(['\PHPDaemon\Core\Daemon', 'errorHandler']);

		Daemon::checkSupports();

		Daemon::$initservervar = $_SERVER;
		Daemon::$masters       = new Collection;
		Daemon::$shm_wstate    = new ShmEntity(Daemon::$config->pidfile->value, Daemon::SHM_WSTATE_SIZE, 'wstate', true);
		Daemon::openLogs();

	}

	/**
	 * Is thing supported
	 * @param integer $what Thing to check
	 * @return boolean
	 */
	public static function supported($what) {
		return isset(self::$support[$what]);
	}

	/**
	 * Method to fill $support array
	 * @return void
	 */
	protected static function checkSupports() {
		if (is_callable('runkit_lint_file')) {
			self::$support[self::SUPPORT_RUNKIT_SANDBOX] = true;
		}

		if (is_callable('runkit_function_add')) {
			self::$support[self::SUPPORT_RUNKIT_MODIFY] = true;
		}

		if (is_callable('runkit_import')) {
			self::$support[self::SUPPORT_RUNKIT_IMPORT] = true;
		}

		if (
				self::supported(self::SUPPORT_RUNKIT_MODIFY)
				&& ini_get('runkit.internal_override')
		) {
			self::$support[self::SUPPORT_RUNKIT_INTERNAL_MODIFY] = true;
		}
	}

	/**
	 * Check file syntax via runkit_lint_file if supported or via php -l
	 * @param string File name
	 * @return boolean
	 */
	public static function lintFile($filename) {
		if (!file_exists($filename)) {
			return false;
		}

		if (self::supported(self::SUPPORT_RUNKIT_SANDBOX)) {
			/** @noinspection PhpUndefinedFunctionInspection */
			return runkit_lint_file($filename);
		}

		$cmd = 'php -l ' . escapeshellcmd($filename) . ' 2>&1';

		exec($cmd, $output, $retcode);

		return 0 === $retcode;
	}

	/**
	 * It allows to run your simple web-apps in spawn-fcgi/php-fpm environment.
	 * @return boolean|null - Success.
	 */
	public static function compatRunEmul() {
		Daemon::$compatMode = TRUE;
		Daemon::initSettings();

		$argv = isset($_SERVER['CMD_ARGV']) ? $_SERVER['CMD_ARGV'] : '';

		$args = \PHPDaemon\Core\Bootstrap::getArgs($argv);

		if (isset($args[$k = 'configfile'])) {
			Daemon::$config[$k] = $args[$k];
		}

		if (!Daemon::$config->loadCmdLineArgs($args)) {
			$error = true;
		}

		if (isset(Daemon::$config->configfile->value) && !Daemon::loadConfig(Daemon::$config->configfile->value)) {
			$error = true;
		}

		if (!isset(Daemon::$config->path->value)) {
			exit('\'path\' is not defined');
		}

		if ($error) {
			exit;
		}

		$appResolver = require Daemon::$config->path->value;
		$appResolver->init();

		$req                    = new \stdClass;
		$req->attrs             = new \stdClass;
		$req->attrs->request    = $_REQUEST;
		$req->attrs->get        = $_GET;
		$req->attrs->post       = $_REQUEST;
		$req->attrs->cookie     = $_REQUEST;
		$req->attrs->server     = $_SERVER;
		$req->attrs->files      = $_FILES;
		$req->attrs->session    = isset($_SESSION) ? $_SESSION : null;
		$req->attrs->connId     = 1;
		$req->attrs->trole      = 'RESPONDER';
		$req->attrs->flags      = 0;
		$req->attrs->id         = 1;
		$req->attrs->paramsDone = true;
		$req->attrs->inputDone  = true;
		$req                    = $appResolver->getRequest($req);

		while (true) {
			$ret = $req->call();

			if ($ret === 1) {
				return;
			}
		}
	}

	/**
	 * Load config-file
	 * @param string $paths Path
	 * @return boolean - Success.
	 */
	public static function loadConfig($paths) {
		$apaths = explode(';', $paths);
		foreach ($apaths as $path) {
			if (is_file($p = realpath($path))) {
				$ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
				if ($ext === 'conf') {
					return Daemon::$config->loadFile($p);
				}
				else {
					Daemon::log('Config file \'' . $p . '\' has unsupported file extension.');
					return false;
				}
			}
		}
		Daemon::log('Config file not found in \'' . $paths . '\'.');
		return false;
	}

	/**
	 * Open logs.
	 * @return void
	 */
	public static function openLogs() {
		if (Daemon::$config->logging->value) {
			Daemon::$logpointer = fopen(Daemon::$config->logstorage->value, 'a');
			if (isset(Daemon::$config->group->value)) {
				chgrp(Daemon::$config->logstorage->value, Daemon::$config->group->value); // @TODO: rewrite to async I/O
			}
			if (isset(Daemon::$config->user->value)) {
				chown(Daemon::$config->logstorage->value, Daemon::$config->user->value); // @TODO: rewrite to async I/O
			}
			if ((Daemon::$process instanceof Thread\Worker) && FileSystem::$supported) {
				FileSystem::open(Daemon::$config->logstorage->value, 'a!', function ($file) {
					Daemon::$logpointerAsync = $file;
					if (!$file) {
						return;
					}
				});
			}
		}
		else {
			Daemon::$logpointer      = null;
			Daemon::$logpointerAsync = null;
		}
	}

	/**
	 * Get state of workers.
	 * @return array - information.
	 */
	public static function getStateOfWorkers() {
		$offset  = 0;

		$stat = [
			'idle'      => 0,
			'busy'      => 0,
			'alive'     => 0,
			'shutdown'  => 0,
			'preinit'   => 0,
			'init'      => 0,
			'reloading' => 0,
		];
		$readed = 0;
		$readedStr = '';
		while (($buf = Daemon::$shm_wstate->read($readed, 1024)) !== false) {
			$readed += strlen($buf);
			$readedStr .= $buf;
			for ($i = 0, $buflen = strlen($buf); $i < $buflen; ++$i) {
				$code = ord($buf[$i]);
				if ($code >= 100) {
					// reloaded (shutdown)
					$code -= 100;
					if ($code !== Daemon::WSTATE_SHUTDOWN) {
						++$stat['alive'];
						if (Daemon::$process instanceof Thread\Master) {
							Daemon::$process->reloadWorker($offset + $i + 1);
							++$stat['reloading'];
							continue;
						}
					}
				}
				if ($code === Daemon::WSTATE_IDLE) {
					// idle
					++$stat['alive'];
					++$stat['idle'];
				}
				elseif ($code === Daemon::WSTATE_BUSY) {
					// busy
					++$stat['alive'];
					++$stat['busy'];
				}
				elseif ($code === Daemon::WSTATE_SHUTDOWN) {
					// shutdown
					++$stat['shutdown'];
				}
				elseif ($code === Daemon::WSTATE_PREINIT) {
					// pre-init
					++$stat['alive'];
					++$stat['preinit'];
					++$stat['idle'];
				}
				elseif ($code === Daemon::WSTATE_INIT) { // init
					++$stat['alive'];
					++$stat['init'];
					++$stat['idle'];
				}
			}
		}
		//Daemon::log('readedStr: '.Debug::exportBytes($readedStr, true));
		return $stat;
	}

	/**
	 * Send message to log.
	 * @param  mixed  ...$args Arguments
	 * @return string message
	 */
	public static function log() {
		$args = func_get_args();
		if (sizeof($args) === 1) {
			$msg = is_scalar($args[0]) ? $args[0] : Debug::dump($args[0]);
		}
		else {
			$msg = Debug::dump($args);
		}
		$mt = explode(' ', microtime());

		//$msg = substr($msg, 0, 1024) . Debug::backtrace();
		if (is_resource(STDERR)) {
			fwrite(STDERR, '[PHPD] ' . $msg . "\n");
		}

		$msg = str_replace("\x01", $msg, date(strtr(Daemon::$config->logformat->value, ['%msg%' => "\x01", '\\u' => '\\u', 'u' => sprintf('%06d', $mt[0] * 1000000)]))) . "\n";

		if (Daemon::$logpointerAsync) {
			Daemon::$logpointerAsync->write($msg);
		}
		elseif (Daemon::$logpointer) {
			fwrite(Daemon::$logpointer, $msg);
		}
	}

	/**
	 * spawn new master process.
	 * @return null|integer - success
	 */
	public static function spawnMaster() {
		Daemon::$masters->push($thread = new Thread\Master);
		$thread->start();

		if (-1 === $thread->getPid()) {
			Daemon::log('could not start master');
			exit(0);
		}

		return $thread->getPid();
	}

	/**
	 * Run worker thread
	 * @return void
	 */
	public static function runWorker() {
		Daemon::$runworkerMode = true;
		$thread = new Thread\Worker;
		$thread();
	}

}
