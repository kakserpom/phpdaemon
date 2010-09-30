<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Daemon
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description Core class.
/**************************************************************************/

class Daemon {

	const SUPPORT_RUNKIT_SANDBOX = 0;
	const SUPPORT_RUNKIT_MODIFY  = 1;

	/**
	 * PHPDaemon root directory
	 * @access private
	 * @var string
	 */
	public static $dir;
	
	/**
	 * PHPDaemon version
	 * @access public
	 * @var string
	 */
	public static $version;

	/**
	 * PHPDaemon start time
	 * @access private
	 * @var integer
	 */
	public static $startTime;
	
	/**
	 * Log file resource
	 * @access private
	 * @var resource
	 */
	private static $logpointer;
	
	/**
	 * Log file path
	 * @access public
	 * @var string
	 */
	public static $logpointerpath;

	/**
	 * Supported things array
	 * @access private
	 * @var string
	 */
	private static $support = array();
	
	public static $pathReal;
	public static $worker;
	public static $appResolver;
	public static $appInstances = array();
	public static $sockCounter = 0;
	public static $sockets = array();
	public static $socketEvents = array();
	public static $req;
	public static $settings = array();
	public static $parsedSettings = array();
	private static $workers;
	private static $masters;
	private static $initservervar;
	public static $shm_wstate;
	private static $shm_wstate_size = 5120;
	public static $useSockets;
	public static $compatMode = FALSE;
	public static $runName = 'phpdaemon';
	public static $dummyRequest;

	/**
	 * @method initSettings
	 * @description Loads default setting.
	 * @return void
	 */
	public static function initSettings() {
		Daemon::$version = file_get_contents(Daemon::$dir . '/VERSION');

		Daemon::$settings = array(
			// Worker graceful restarting:
			'maxrequests' => '1k',
			'maxmemoryusage' => '0b',
			'maxidle' => '0s',
			
			// Main Pathes
			'pidfile' => '/var/run/phpd.pid',
			'defaultpidfile' => '/var/run/phpd.pid',
			'configfile' => '/etc/phpd/phpd.conf;' . Daemon::$dir . '/conf/phpdaemon.conf.php',
			'path' => NULL,
			'ipcwstate' => '/var/run/phpdaemon-wstate.ipc',
			
			// Master-related
			'mpmdelay'=> '1s',
			'startworkers' => 20,
			'minworkers' => 20,
			'maxworkers' => 80,
			'minspareworkers' => 20,
			'maxspareworkers' => 50,
			'masterpriority' => 100,
			 
			 // Requests
			'obfilterauto' => 1,
			'expose' => 1,
			'keepalive' => '0s',
			'autoreadbodyfile' => 1,
			'chunksize' => '8k',
			'maxconcurrentrequestsperworker' => 1000,
			
			 // Worker-related
			'user' => NULL,
			'group' => NULL,
			'autogc' => '1',
			'chroot' => '/',
			'cwd' => '.',
			'autoreload' => '0s',
			'autoreimport' => 0,
			'microsleep' => 100000,
			'workerpriority' => 4,
			'throwexceptiononshutdown' => 0,
			'locale' => '',
			
			 // Logging-related
			'logging' => 1,
			'logtostderr' => 1,
			'logstorage' => '/var/log/phpdaemon.log',
			'logerrors' => 1,
			'logworkersetstatus' => 0,
			'logevents' => 0,
			'logqueue' => 0,
			'logreads' => 0,
			'logsignals' => 0,
		);

		Daemon::loadSettings(Daemon::$settings);

		Daemon::$useSockets = version_compare(PHP_VERSION, '5.3.1', '>=');

		Daemon::$dummyRequest = new stdClass;
		Daemon::$dummyRequest->attrs = new stdClass;
		Daemon::$dummyRequest->attrs->stdin_done = TRUE;
		Daemon::$dummyRequest->attrs->params_done = TRUE;
		Daemon::$dummyRequest->attrs->chunked = FALSE;
	}

	/**
	 * @method addDefaultSettings
	 * @param array {"setting": "value"}
	 * @description Adds default settings to repositoty.
	 * @return boolean - Succes.
	 */
	public static function addDefaultSettings($settings = array()) {
		foreach ($settings as $k => $v) {
			$k = strtolower(str_replace('-', '', $k));

			if (!isset(Daemon::$settings[$k])) {
				Daemon::$settings[$k] = $v;
			}
		}

		return TRUE;
	}

	/**
	 * @method outputFilter
	 * @param string - String.
	 * @description Callback-function, output filter.
	 * @return string - buffer
	 */
	public static function outputFilter($s) {
		if ($s === '') {
			return '';
		}

		if (
			Daemon::$settings['obfilterauto'] 
			&& (Daemon::$req !== NULL)
		) {
			Daemon::$req->out($s,FALSE);
		} else {
			Daemon::log('Unexcepted output (len. ' . strlen($s) . '): \'' . $s . '\'');
		}

		return '';
	}

	/**
	 * @method getMIME()
	 * @param string - Path
	 * @description Returns MIME type of the given file.
	 * @return string - MIME type.
	 */
	public static function getMIME($path) {
		static $types = array(
			'txt'  => 'text/plain',
			'htm'  => 'text/html',
			'html' => 'text/html',
			'php'  => 'text/html',
			'css'  => 'text/css',
			'js'   => 'application/javascript',
			'json' => 'application/json',
			'xml'  => 'application/xml',
			'swf'  => 'application/x-shockwave-flash',
			'flv'  => 'video/x-flv',

			// images
			'png'  => 'image/png',
			'jpe'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'jpg'  => 'image/jpeg',
			'gif'  => 'image/gif',
			'bmp'  => 'image/bmp',
			'ico'  => 'image/vnd.microsoft.icon',
			'tiff' => 'image/tiff',
			'tif'  => 'image/tiff',
			'svg'  => 'image/svg+xml',
			'svgz' => 'image/svg+xml',

			// archives
			'zip' => 'application/zip',
			'rar' => 'application/x-rar-compressed',
			'exe' => 'application/x-msdownload',
			'msi' => 'application/x-msdownload',
			'cab' => 'application/vnd.ms-cab-compressed',

			// audio/video
			'mp3' => 'audio/mpeg',
			'qt'  => 'video/quicktime',
			'mov' => 'video/quicktime',

			// adobe
			'pdf' => 'application/pdf',
			'psd' => 'image/vnd.adobe.photoshop',
			'ai'  => 'application/postscript',
			'eps' => 'application/postscript',
			'ps'  => 'application/postscript',

			// ms office
			'doc' => 'application/msword',
			'rtf' => 'application/rtf',
			'xls' => 'application/vnd.ms-excel',
			'ppt' => 'application/vnd.ms-powerpoint',

			// open office
			'odt' => 'application/vnd.oasis.opendocument.text',
			'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
		);

		$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

		if (isset($types[$ext])) {
			return $types[$ext];
		}
		elseif (function_exists('finfo_open')) {
			if (!is_readable($path)) {
				return 'application/octet-stream';
			}

			$finfo = finfo_open(FILEINFO_MIME);
			$mimetype = finfo_file($finfo, $path);

			finfo_close($finfo);

			return $mimetype;
		} else {
			return 'application/octet-stream';
		}
	}

	/**
	 * @method header
	 * @param string
	 * @description Static wrapper of Request->header().
	 * @return boolean
	 */
	public static function header($h) {
		if (Daemon::$req === NULL) {
			throw new Exception('Daemon::header() called out of request context');
		}

		return Daemon::$req->header($h);
	}

	/**
	 * Is thing supported
	 * @param $what integer Thing to check
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
			self::$support[self::SUPPORT_RUNKIT_SANDBOX] = 1;
		}

		if (is_callable('runkit_function_add')) {
			self::$support[self::SUPPORT_RUNKIT_MODIFY] = 1;
		}
	}

	/**
	 * Check file syntax via runkit_lint_file if supported or via php -l
	 * @param $filaname string File name
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
	 * @method init
	 * @description Performs initial actions.
	 * @return void
	 */
	public static function init() {
		Daemon::$startTime = time();
		set_time_limit(0);

		ob_start(array('Daemon', 'outputFilter'));

		Daemon::checkSupports();

		Daemon::$initservervar = $_SERVER;
		Daemon::$masters = new threadCollection;
		Daemon::$shm_wstate = Daemon::shmop_open(Daemon::$settings['ipcwstate'],Daemon::$shm_wstate_size,'wstate');
		Daemon::openLogs();
	}

	/**
	 * @method getBacktrace
	 * @description Returns textual backtrace.
	 * @return void
	 */
	public static function getBacktrace() {
		ob_start();
		debug_print_backtrace();
		$dump = ob_get_contents();
		ob_end_clean();

		return $dump;
	}

	/**
	 * @method humanSize
	 * @description Returns human-readable size.
	 * @return void
	 */
	public static function humanSize($size) {
		if ($size >= 1073741824) {
			$size = round($size / 1073741824, 2) . 'G';
		}
		elseif ($size >= 1048576) {
			$size = round($size / 1048576, 2) .'M';
		}
		elseif ($size >= 1024) {
			$size = round($size / 1024 * 100, 2) .'K';
		} else {
			$size = $size . 'B';
		}

		return $size;
	}

	/**
	 * @method compatRunEmul
	 * @description It allows to run your simple web-apps in spawn-fcgi/php-fpm environment.
	 * @return boolean - Success.
	 */
	public static function compatRunEmul() {
		Daemon::$dir = realpath(__DIR__ . '/..');
		Daemon::$compatMode = TRUE;
		Daemon::initSettings();

		$argv = isset($_SERVER['CMD_ARGV']) ? $_SERVER['CMD_ARGV'] : '';

		$args = Daemon_Bootstrap::getArgs($argv);

		if (isset($args[$k = 'configfile'])) {
			Daemon::$settings[$k] = $args[$k];
		}

		if (
			isset(Daemon::$parsedSettings['configfile']) 
			&& !Daemon::loadConfig(Daemon::$parsedSettings['configfile'])
		) {
			$error = TRUE;
		}

		if (!Daemon::loadSettings($args)) {
			$error = TRUE;
		}

		if (!isset(Daemon::$settings['path'])) {
			exit('\'path\' is not defined');
		}

		$appResolver = require Daemon::$settings['path'];
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
	 * @method loadConfig
	 * @param string Path
	 * @description Loads config-file. 
	 * @return boolean - Success.
	 */
	public static function loadConfig($paths) {
		$apaths = explode(';', $paths);
		$found = false;

		foreach($apaths as $path) {
			if (is_file($p = realpath($path))) {
				$found = true;

				$cfg = include($p);

				if (!is_array($cfg)) {
					Daemon::log('Config file \'' . $p . '\' returns bad value.');
					continue;
				}
				
				if (!Daemon::loadSettings($cfg)) {
					Daemon::log('Couldn\'t load config file \'' . $p . '\'.');
					continue;
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
	 * @method loadSetings
	 * @param array Settings.
	 * @description Checks and loads settings to registry.
	 * @return boolean - Success.
	*/
	public static function loadSettings($settings) {
		$error = FALSE;

		static $ktr = array(
			'-' => '',
		);

		foreach ($settings as $k => $v) {
			$k = strtolower(strtr($k, $ktr));

			if ($k === 'config') {
				$k = 'configfile';
			}

			if (
				($k === 'user') 
				|| ($k === 'group')
			) {
				if ($v === '') {
					$v = NULL;
				}
			}

			if (array_key_exists($k, Daemon::$settings)) {
				if ($k === 'maxmemoryusage') {
					Daemon::$parsedSettings[$k] = Daemon::parseSize($v);
				}
				elseif ($k === 'maxrequests') {
					Daemon::$parsedSettings[$k] = Daemon::parseNum($v);
				}
				elseif ($k === 'autogc') {
					Daemon::$parsedSettings[$k] = Daemon::parseNum($v);
				}
				elseif ($k === 'maxidle') {
					Daemon::$parsedSettings[$k] = Daemon::parseTime($v);
				}
				elseif ($k === 'autoreload') {
					Daemon::$parsedSettings[$k] = Daemon::parseTime($v);
				}
				elseif ($k === 'maxconcurrentrequestsperworker') {
					Daemon::$parsedSettings[$k] = Daemon::parseNum($v);
				}
				elseif ($k === 'keepalive') {
					Daemon::$parsedSettings[$k] = Daemon::parseTime($v);
				}
				elseif ($k === 'mpmdelay') {
					Daemon::$parsedSettings[$k] = Daemon::parseTime($v);
				}
				elseif ($k === 'chunksize') {
					Daemon::$parsedSettings[$k] = Daemon::parseSize($v);
				}
				elseif ($k === 'configfile') {
					Daemon::$parsedSettings[$k] = realpath($v);
				}

				if (is_int(Daemon::$settings[$k])) {
					Daemon::$settings[$k] = (int) $v;
				} else {
					Daemon::$settings[$k] = $v;
				}
			}
			elseif (strpos($k, 'mod') === 0) {
				Daemon::$settings[$k] = $v;
			} else {
				Daemon::log('Unrecognized parameter \'' . $k . '\'');
				$error = TRUE;
			}
		}

		if (isset($settings['path'])) {
			if (isset(Daemon::$settings['chroot'])) {
				Daemon::$pathReal = realpath(Daemon::$settings['chroot']
				. ((substr(Daemon::$settings['chroot'], -1) != '/') ? '/' : '')
				. Daemon::$settings['path']);
			} else {
				Daemon::$pathReal = realpath(Daemon::$settings['path']);
			}
		}

		return !$error;
	}

	/**
	 * @method openLogs
	 * @description Open log descriptors.
	 * @return void
	 */
	public static function openLogs() {
		if (Daemon::$settings['logging']) {
			if (Daemon::$logpointer) {
				fclose(Daemon::$logpointer);
				Daemon::$logpointer = FALSE;
			}

			Daemon::$logpointer = fopen(Daemon::$logpointerpath = Daemon::parseStoragepath(Daemon::$settings['logstorage']), 'a+');

			if (isset(Daemon::$settings['group'])) {
				chgrp(Daemon::$logpointerpath, Daemon::$settings['group']);
			}

			if (isset(Daemon::$settings['user'])) {
				chown(Daemon::$logpointerpath, Daemon::$settings['user']);
			}
		}
	}

	/**
	 * @method parseSize
	 * @param string $str - size-string to parse.
	 * @description Converts string representation of size (INI-style) to bytes.
	 * @return int - size in bytes.
	 */
	public static function parseSize($str) {
		$l = strtolower(substr($str, -1));

		if ($l === 'b') {
			return ((int) substr($str, 0, -1));
		}

		if ($l === 'k') {
			return ((int) substr($str, 0, -1) * 1024);
		}

		if ($l === 'm') {
			return ((int) substr($str, 0, -1) * 1024 * 1024);
		}

		if ($l === 'g') {
			return ((int) substr($str, 0, -1) * 1024 * 1024 * 1024);
		}

		return $str;
	}

	/**
	 * @method parseNum
	 * @param string $str - number-string to parse.
	 * @description Converts string representation of number (INI-style) to integer.
	 * @return int - number.
	*/
	public static function parseNum($str) {
		$l = substr($str, -1);

		if (
			($l === 'k') 
			|| ($l === 'K')
		) {
			return ((int) substr($str, 0, -1) * 100);
		}

		if (
			($l === 'm') 
			|| ($l === 'M')
		) {
			return ((int) substr($str, 0, -1) * 1000 * 1000);
		}

		if (
			($l === 'G') 
			|| ($l === 'G')
		) {
			return ((int) substr($str, 0, -1) * 1000 * 1000 * 1000);
		}

		return (int) $str;
	}

	/**
	 * @method parseTime
	 * @param string $str - time-string to parse.
	 * @description Converts string representation of time (INI-style) to seconds.
	 * @return int - seconds.
	 */
	public static function parseTime($str) {
		$time = 0;

		preg_replace_callback('~(\d+)\s*([smhd])\s*|(.+)~i', function($m) use (&$time) {
			if (
				isset($m[3]) 
				&& ($m[3] !== '')
			) {
				$time = FALSE;
			}

			if ($time === FALSE) {
				return;
			}

			$n = (int) $m[1];
			$l = strtolower($m[2]);

			if ($l === 's') {
				$time += $n;
			}
			elseif ($l === 'm') {
				$time += $n * 60;
			}
			elseif ($l === 'h') {
				$time += $n * 60 * 60;
			}
			elseif ($l === 'd') {
				$time += $n * 60 * 60 * 24;
			}
		}, $str);

		return $time;
	}

	/**
	 * @method getStateOfWorkers
	 * @description Get state of workers.
	 * @return array - information.
	 */
	public static function getStateOfWorkers($master = NULL) {
		static $bufsize = 1024;
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

		$c = 0;

		while ($offset < Daemon::$shm_wstate_size) {
			$buf = shmop_read(Daemon::$shm_wstate, $offset, $bufsize);

			for ($i = 0; $i < $bufsize; ++$i) {
				$code = ord($buf[$i]);
				if ($code >= 100) {
					 // reloaded (shutdown)
					$code -= 100;

					if ($master !== NULL) {
						$master->reloadWorker($offset + $i + 1);
					}
				}

				if ($code === 0) {
					break 2;
				}
				elseif ($code === 1) {
					// idle
					++$stat['alive'];
					++$stat['idle'];
				}
				elseif ($code === 2) {
					// busy
					++$stat['alive'];
					++$stat['busy'];
				}
				elseif ($code === 3) { 
					// shutdown
					++$stat['shutdown'];
				}
				elseif ($code === 4) {
					// pre-init
					++$stat['alive'];
					++$stat['preinit'];
					++$stat['idle'];
				}
				elseif ($code === 5) {
					// wait-init
					++$stat['alive'];
					++$stat['waitinit'];
					++$stat['idle'];
				}
				elseif ($code === 6) { // init
					++$stat['alive'];
					++$stat['init'];
					++$stat['idle'];
				}

				++$c;
			}

			$offset += $bufsize;
		}

		return $stat;
	}

	/**
	 * @method shmop_open
	 * @param string Path to file.
	 * @param int Size of segment.
	 * @param string Name of segment.
	 * @param boolean Whether to create if it doesn't exist.
	 * @description Opens segment of shared memory.
	 * @return int Resource ID.
	 */
	public static function shmop_open($path, $size, $name, $create = TRUE) {
		if ($create) {
			if (!touch($path)) {
				Daemon::log('Couldn\'t touch IPC file \'' . $path . '\'.');
				exit(0);
			}
		}

		if (($key = ftok($path,'t')) === FALSE) {
			Daemon::log('Couldn\'t ftok() IPC file \'' . $path . '\'.');
			exit(0);
		}

		if (!$create) {
			$shm = shmop_open($key, 'w', 0, 0);
		} else {
			$shm = @shmop_open($key, 'w', 0, 0);

			if ($shm) {
				shmop_delete($shm);
				shmop_close($shm);
			}

			$shm = shmop_open($key, 'c', 0755, $size);
		}

		if (!$shm) {
			Daemon::log('Couldn\'t open IPC-' . $name . ' shared memory segment (key=' . $key . ', size=' . $size . ', uid=' . posix_getuid() . ').');
			exit(0);
		}

		return $shm;
	}

	/**
	 * @method log
	 * @param string $msg - message.
	 * @description Send message to log.
	 * @return string - message
	 */
	public static function log($msg) {
		$mt = explode(' ', microtime());

		if (
			Daemon::$settings['logtostderr'] 
			&& defined('STDERR')
		) {
			fwrite(STDERR, '[PHPD] ' . $msg . "\n");
		}

		if (Daemon::$logpointer) {
			fwrite(Daemon::$logpointer, '[' . date('D, j M Y H:i:s', $mt[1]) . '.' . sprintf('%06d', $mt[0]*1000000) . ' ' . date('O') . '] ' . $msg . "\n");
		}
	}

	/**
	 * @method parseStoragepath
	 * @param string $path - path to parse.
	 * @description it replaces meta-variables in path with values.
	 * @return string - path
	 */
	public static function parseStoragepath($path) {
		$path = preg_replace_callback(
			'~%(.*?)%~', 
			function($m) {
				$e = explode('=', $m[1]);

				if (strtolower($e[0]) == 'date') {
					return date($e[1]);
				}

				return $m[0];
			}, $path);

		if (stripos($path, 'file://') === 0) {
			$path = substr($path, 7);
		}

		return $path;
	}

	/**
	 * @method spawnMaster
	 * @description spawn new master process.
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
	 * @method exportBytes
	 * @param string String.
	 * @param boolean Whether to replace all of chars with escaped sequences.
	 * @description Exports binary data.
	 * @return string - Escaped string.
	 */
	public static function exportBytes($str, $all = FALSE) {
		return preg_replace_callback(
			'~' . ($all ? '.' : '[^A-Za-z\d\.$:;\-_/\\\\]') . '~s',
			function($m) use ($all) {
				if (!$all) {
					if ($m[0] == "\r") {
						return "\n" . '\r';
					}

					if ($m[0] == "\n") {
						return '\n';
					}
				}

				return sprintf('\x%02x', ord($m[0]));
			}, $str);
	}

	/**
	 * @method var_dump
	 * @description Wrapper of var_dump.
	 * @return string - Result of var_dump().
	 */
	public static function var_dump() {
		ob_start();

		foreach (func_get_args() as $v) {
			var_dump($v);
		}

		$dump = ob_get_contents();
		ob_end_clean();

		return $dump;
	}

	/**
	 * @method date_period
	 * @description Calculates a difference between two dates.
	 * @return array [seconds,minutes,hours,days,months,years]
	 */
	public function date_period($st, $fin) {
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
	 * @method date_period_text
	 * @description Calculates a difference between two dates.
	 * @return string Something like this: 1 year. 2 mon. 6 day. 4 hours. 21 min. 10 sec.
	 */
	function date_period_text($date_start, $date_finish) {
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
