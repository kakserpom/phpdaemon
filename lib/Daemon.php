<?php

/**
 * Daemon "namespace"
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon {

	const SUPPORT_RUNKIT_SANDBOX         = 0;	
	const SUPPORT_RUNKIT_MODIFY          = 1;
	const SUPPORT_RUNKIT_INTERNAL_MODIFY = 2;
	const SUPPORT_RUNKIT_IMPORT 		 = 3;
	const WSTATE_IDLE = 1;
	const WSTATE_BUSY = 2;
	const WSTATE_SHUTDOWN = 3;
	const WSTATE_PREINIT = 4;
	const WSTATE_WAITINIT = 5;
	const WSTATE_INIT = 6;
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

	public static $logpointerAsync;
	
	/**	
	 * Supported things array	
	 * @var string	
	 */	
	private static $support = array();
	
	public static $process;
	public static $appResolver;
	public static $appInstances = array();
	public static $req;
	public static $context;
	private static $workers;
	private static $masters;
	private static $initservervar;
	public static $shm_wstate;
	public static $reusePort;
	public static $compatMode = FALSE;
	public static $runName = 'phpdaemon';
	public static $config;
	public static $appResolverPath;
	public static $restrictErrorControl = false;
	public static $defaultErrorLevel;

	public static $obInStack = false; // whether if the current execution stack contains ob-filter
	/**
	 * Loads default setting.
	 * @return void
	 */
	public static function initSettings() {
		Daemon::$version = file_get_contents('VERSION', true);

		Daemon::$config = new Daemon_Config;

		// currently re-using listener ports across multiple processes is available
		// only in BSD flavour operating systems via SO_REUSEPORT socket option
		Daemon::$reusePort = 1 === preg_match("~BSD~i", php_uname('s'));
		
		if (Daemon::$reusePort && !defined("SO_REUSEPORT"))
		    define("SO_REUSEPORT", 0x200);	// FIXME: this is a BSD-only hack
	}

	public static function loadModuleIfAbsent($mod) {
		if (extension_loaded($mod)) {
			return true;
		}
		if (!get_cfg_var('enable_dl')) {
			return false;
		}
		return @dl(basename($mod) . '.so');
	}

	public static function callAutoGC() {
		if (
			(Daemon::$config->autogc->value > 0) 
			&& (Daemon::$process->counterGC > 0) 
			&& (Daemon::$process->counterGC >= Daemon::$config->autogc->value)
		) {
			gc_collect_cycles();
			Daemon::$process->counterGC = 0;
		}
	}

	/**
	 * Callback-function, output filter.
	 * @param string - String.
	 * @return string - buffer
	 */
	public static function outputFilter($s) {
		static $n = 0; // recursion counter
		
		if ($s === '') {
			return '';
		}
		++$n;
		Daemon::$obInStack = true;
		if (
			Daemon::$config->obfilterauto->value
			&& (Daemon::$req !== NULL)
		) {
			Daemon::$req->out($s, false);

		} else {
			Daemon::log('Unexcepted output (len. ' . strlen($s) . '): \'' . $s . '\'');
		}
		--$n;
		Daemon::$obInStack = $n > 0;
		return '';
	}

	public static function uncaughtExceptionHandler(Exception $e) {
		$msg = $e->getMessage();
		Daemon::log('Uncaught '.get_class($e).' (' . $e->getCode() . ')' . (strlen($msg) ? ': ' . $msg : ''). ".\n" . $e->getTraceAsString());
		if (Daemon::$req) {
			Daemon::$req->out('<b>Uncaught '.get_class($e).' (' . $e->getCode() . ')</b>' . (strlen($msg) ? ': ' . $msg : ''). '.<br />');
		}
	}

	public static function errorHandler($errno, $errstr, $errfile, $errline, $errcontext) {
		$l = error_reporting();
		if ($l === 0) {
			if (!Daemon::$restrictErrorControl) {
				return;
			}
		}
		elseif (!($l & $errno)) {
			return;
		}

		static $errtypes = array(
			E_ERROR => 'Fatal error',
			E_WARNING => 'Warning',
			E_PARSE => 'Parse error',
			E_NOTICE => 'Notice',
			E_PARSE => 'Parse error',
			E_USER_ERROR => 'Fatal error (userland)',
			E_USER_WARNING => 'Warning (userland)',
			E_USER_NOTICE => 'Notice (userland)',
			E_STRICT => 'Notice (userland)',
			E_RECOVERABLE_ERROR => 'Fatal error (recoverable)',
			E_DEPRECATED => 'Deprecated',
			E_USER_DEPRECATED => 'Deprecated (userland)',
		);
		$errtype = $errtypes[$errno];
		Daemon::log($errtype . ': '.$errstr.' in ' . $errfile . ':' . $errline . "\n" . Debug::backtrace());
		if (Daemon::$req) {
			Daemon::$req->out('<strong>' . $errtype . '</strong>: ' . $errstr . ' in ' . basename($errfile) . ':' . $errline.'<br />');
		}
	}

	/**
	 * Performs initial actions.
	 * @return void
	 */
	public static function init() {
		Daemon::$startTime = time();
		set_time_limit(0);

		Daemon::$defaultErrorLevel = error_reporting();
		Daemon::$restrictErrorControl = (bool) Daemon::$config->restricterrorcontrol->value; 
		ob_start(array('Daemon', 'outputFilter'));
		set_error_handler(array('Daemon', 'errorHandler'));
		
		Daemon::checkSupports();

		Daemon::$initservervar = $_SERVER;
		Daemon::$masters = new ThreadCollection;
		Daemon::$shm_wstate = new ShmEntity(Daemon::$config->pidfile->value, Daemon::SHM_WSTATE_SIZE, 'wstate', true);
		Daemon::openLogs();
	}
	

	/**
	 * Is thing supported
	 * @param integer Thing to check
	 * @return boolean
	 */
	public static function supported($what) {
		return isset(self::$support[$what]);
	}

	/**
	 * Method to fill $support array
	 * @return void
	 */
	private static function checkSupports() {
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
			return runkit_lint_file($filename);
		}

		$cmd = 'php -l ' . escapeshellcmd($filename) . ' 2>&1';

		exec($cmd, $output, $retcode);

		return 0 === $retcode;
	}

	/**
	 * It allows to run your simple web-apps in spawn-fcgi/php-fpm environment.
	 * @return boolean - Success.
	 */
	public static function compatRunEmul() {
		Daemon::$dir = realpath(__DIR__ . '/..');
		Daemon::$compatMode = TRUE;
		Daemon::initSettings();

		$argv = isset($_SERVER['CMD_ARGV']) ? $_SERVER['CMD_ARGV'] : '';

		$args = Daemon_Bootstrap::getArgs($argv);

		if (isset($args[$k = 'configfile'])) {
			Daemon::$config[$k] = $args[$k];
		}

		if (!Daemon::$config->loadCmdLineArgs($args)) {
			$error = TRUE;
		}

		if (
			isset(Daemon::$config->configfile->value) 
			&& !Daemon::loadConfig(Daemon::$config->configfile->value)
		) {
			$error = TRUE;
		}
		
		if (!isset(Daemon::$config->path->value)) {
			exit('\'path\' is not defined');
		}

		$appResolver = require Daemon::$config->path->value;
		$appResolver->init();

		$req = new stdClass();
		$req->attrs = new stdClass();
		$req->attrs->request = $_REQUEST;
		$req->attrs->get = $_GET;
		$req->attrs->post = $_REQUEST;
		$req->attrs->cookie = $_REQUEST;
		$req->attrs->server = $_SERVER;
		$req->attrs->files = $_FILES;
		$req->attrs->session = isset($_SESSION)?$_SESSION:NULL;
		$req->attrs->connId = 1;
		$req->attrs->trole = 'RESPONDER';
		$req->attrs->flags = 0;
		$req->attrs->id = 1;
		$req->attrs->params_done = TRUE;
		$req->attrs->stdin_done = TRUE;
		$req = $appResolver->getRequest($req);

		 while (TRUE) {
			$ret = $req->call();

			if ($ret === 1) {
				return;
			}
		}
	}

	/**
	 * Load config-file
	 * @param string Path
	 * @return boolean - Success.
	 */
	public static function loadConfig($paths) {
		$apaths = explode(';', $paths);
		$found = false;

		foreach($apaths as $path) {
			if (is_file($p = realpath($path))) {
				$found = true;

				$ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));

				if ($ext == 'conf') {
					return Daemon::$config->loadFile($p);
				} else {
					Daemon::log('Config file \'' . $p . '\' has unsupported file extension.');
					return FALSE;
				}

				return true;
			}
		}

		if (!$found) {
			Daemon::log('Config file not found in \'' . $paths . '\'.');
		}

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
			if ((Daemon::$process instanceof Daemon_WorkerThread) && FS::$supported) {
				FS::open(Daemon::$config->logstorage->value, 'a!', function ($file) {
					Daemon::$logpointerAsync = $file;
					if (!$file) {
						return;
					}
				});
			}
		} else {
			Daemon::$logpointer = null;
			Daemon::$logpointerAsync = null;
		}
	}

	/**
	 * Get state of workers.
	 * @return array - information.
	 */
	public static function getStateOfWorkers() {
		$bufsize = min(1024, Daemon::SHM_WSTATE_SIZE);
		$offset = 0;
		
		$stat = array(
			'idle'     => 0,
			'busy'     => 0,
			'alive'    => 0,
			'shutdown' => 0,
			'preinit'  => 0,
			'waitinit' => 0,
			'init'     => 0,
		);

		Daemon::$shm_wstate->openAll();
		$c = 0;
		foreach (Daemon::$shm_wstate->segments as $shm) {
			while ($offset < Daemon::SHM_WSTATE_SIZE) {
				$buf = shmop_read($shm, $offset, $bufsize);
				for ($i = 0, $buflen = strlen($buf); $i < $buflen; ++$i) {
					$code = ord($buf[$i]);
					if ($code >= 100) {
						 // reloaded (shutdown)
						$code -= 100;
						if ($code !== Daemon::WSTATE_SHUTDOWN) {
							if (Daemon::$process instanceof Daemon_MasterThread) {
								Daemon::$process->reloadWorker($offset + $i + 1);
							}
						}
					}
					if ($code === 0) {
						break 2;
					}
					elseif ($code === Daemon::WSTATE_IDLE) {
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
					elseif ($code === Daemon::WSTATE_WAITINIT) {
						// wait-init
						++$stat['alive'];
						++$stat['waitinit'];
						++$stat['idle'];
					}
					elseif ($code === Daemon::WSTATE_INIT) { // init
						++$stat['alive'];
						++$stat['init'];
						++$stat['idle'];
					}
					++$c;
				}
				$offset += $bufsize;
			}
		}
		return $stat;
	}

	/**
	 * Send message to log.
	 * @param string message.
	 * @return string message
	 */
	public static function log() {
		$args = func_get_args();
		if (sizeof($args) == 1) {
			$msg = is_scalar($args[0]) ? $args[0] : Debug::dump($args[0]);
		}
		else {
			$msg = Debug::dump($args);
		}
	
		$mt = explode(' ', microtime());

		if (is_resource(STDERR)) {
			fwrite(STDERR, '[PHPD] ' . $msg . "\n");
		}
		$msg = '[' . date('D, j M Y H:i:s', $mt[1]) . '.' . sprintf('%06d', $mt[0]*1000000) . ' ' . date('O') . '] ' . $msg . "\n";
		if (Daemon::$logpointerAsync) {
			Daemon::$logpointerAsync->write($msg);
		} elseif (Daemon::$logpointer) {
			fwrite(Daemon::$logpointer, $msg);
		}
	}

	/**
	 * spawn new master process.
	 * @return boolean - success
	 */
	public static function spawnMaster() {
		Daemon::$masters->push($thread = new Daemon_MasterThread);
		$thread->start();

		if (-1 === $thread->pid) {
			Daemon::log('could not start master');
			exit(0);
		}

		return $thread->pid;
	}

	/**
	 * Calculates a difference between two dates.
	 * @return array [seconds, minutes, hours, days, months, years]
	 */
	public static function date_period($st, $fin) {
		if (
			(is_int($st)) 
			|| (ctype_digit($st))
		) {
			$st = date('d-m-Y-H-i-s', $st);
		}

		$st = explode('-', $st);

		if (
			(is_int($fin)) 
			|| (ctype_digit($fin))
		) {
			$fin = date('d-m-Y-H-i-s', $fin);
		}

		$fin = explode('-', $fin);

		if (($seconds = $fin[5] - $st[5]) < 0) {
			$fin[4]--; 
			$seconds += 60;
		}

		if (($minutes = $fin[4] - $st[4]) < 0) {
			$fin[3]--;
			$minutes += 60;
		}

		if (($hours = $fin[3] - $st[3]) < 0) {
			$fin[0]--;
			$hours += 24;
		}

		if (($days = $fin[0] - $st[0]) < 0) {
			$fin[1]--;
			$days += date('t', mktime(1, 0, 0, $fin[1], $fin[0], $fin[2]));
		}

		if (($months = $fin[1] - $st[1]) < 0) {
			$fin[2]--;
			$months += 12;
		}

		$years = $fin[2] - $st[2];

		return array($seconds, $minutes, $hours, $days, $months, $years);
	}

	/**
	 * Calculates a difference between two dates.
	 * @return string Something like this: 1 year. 2 mon. 6 day. 4 hours. 21 min. 10 sec.
	 */
	public static function date_period_text($date_start, $date_finish) {
		$result = Daemon::date_period($date_start, $date_finish);

		$str  = '';

		if ($result[5] > 0) {
			$str .= $result[5] . ' year. ';
		}

		if ($result[4] > 0) {
			$str .= $result[4] . ' mon. ';
		}

		if ($result[3] > 0) {
			$str .= $result[3] . ' day. ';
		}

		if ($result[2] > 0) {
			$str .= $result[2] . ' hour. ';
		}

		if ($result[1] > 0) {
			$str .= $result[1] . ' min. ';
		}

		if (
			$result[0] > 0 
			|| $str == ''
		) {
			$str .= $result[0] . ' sec. ';
		}

		return rtrim($str);
	}
	
}
