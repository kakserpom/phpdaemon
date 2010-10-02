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
	
	public static $worker;
	public static $appResolver;
	public static $appInstances = array();
	public static $sockCounter = 0;
	public static $sockets = array();
	public static $socketEvents = array();
	public static $req;
	private static $workers;
	private static $masters;
	private static $initservervar;
	public static $shm_wstate;
	private static $shm_wstate_size = 5120;
	public static $useSockets;
	public static $compatMode = FALSE;
	public static $runName = 'phpdaemon';
	public static $dummyRequest;
	public static $config;

	/**
	 * @method initSettings
	 * @description Loads default setting.
	 * @return void
	 */
	public static function initSettings() {
		Daemon::$version = file_get_contents(Daemon::$dir . '/VERSION');

		Daemon::$config = new Daemon_Config;

		Daemon::$useSockets = version_compare(PHP_VERSION, '5.3.1', '>=');

		Daemon::$dummyRequest = new stdClass;
		Daemon::$dummyRequest->attrs = new stdClass;
		Daemon::$dummyRequest->attrs->stdin_done = TRUE;
		Daemon::$dummyRequest->attrs->params_done = TRUE;
		Daemon::$dummyRequest->attrs->chunked = FALSE;
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
			Daemon::$config->obfilterauto 
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
	 * @method init
	 * @description Performs initial actions.
	 * @return void
	 */
	public static function init() {
		Daemon::$startTime = time();
		set_time_limit(0);

		ob_start(array('Daemon', 'outputFilter'));

		Daemon::$initservervar = $_SERVER;
		Daemon::$masters = new ThreadCollection;
		Daemon::$shm_wstate = Daemon::shmop_open(Daemon::$config->ipcwstate->value,Daemon::$shm_wstate_size,'wstate');
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
			Daemon::$config[$k] = $args[$k];
		}

		if (
			isset(Daemon::$config->configfile->value) 
			&& !Daemon::loadConfig(Daemon::$config->configfile->value)
		) {
			$error = TRUE;
		}

		if (!Daemon::loadSettings($args)) {
			$error = TRUE;
		}

		if (!isset(Daemon::$config->path)) {
			exit('\'path\' is not defined');
		}

		$appResolver = require Daemon::$config->path;
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

        $ext = strtolower(pathinfo($path,PATHINFO_EXTENSION));
        if ($ext == 'conf') {
					$parser = new Daemon_ConfigParser($p);
					if ($parser->errorneus) {
					 return FALSE;
					}
				}
				else{
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
			if (isset(Daemon::$config->{$k})) {
				if (Daemon::$config->{$k} instanceof Daemon_ConfigEntry) {
					Daemon::$config->{$k}->setHumanValue($v);
				}
				else	{
					if (is_int(Daemon::$config->{$k})) {
						Daemon::$config->{$k} = (int) $v;
					}
				}
			}
			elseif (strpos($k, 'mod-') === 0) {
			  $e = explode('-',strtolower($k),3);
			  $kk = $e[1];
			  $name = str_replace('-',$e[2]);
				if (isset(Daemon::$config->{$kk}->{$name})) {
					if (Daemon::$config->{$kk}->{$name} instanceof Daemon_ConfigEntry) {
						Daemon::$config->{$kk}->{$name}->setHumanValue($v);
					}
					elseif (is_int($this->{$kk}->{$name})) {
					  Daemon::$config->{$kk}->{$name} = (int) $v;
					}
				}
				else {
					Daemon::$config->{$kk}->{$name} = $v;
				}
			}
			else {
				Daemon::log('Unrecognized parameter \'' . $k . '\'');
				$error = TRUE;
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
		if (Daemon::$config->logging) {
			if (Daemon::$logpointer) {
				fclose(Daemon::$logpointer);
				Daemon::$logpointer = FALSE;
			}

			Daemon::$logpointer = fopen(Daemon::$logpointerpath = Daemon::parseStoragepath(Daemon::$config->logstorage->value), 'a+');

			if (isset(Daemon::$config->group)) {
				chgrp(Daemon::$logpointerpath, Daemon::$config->group->value);
			}

			if (isset(Daemon::$config->user)) {
				chown(Daemon::$logpointerpath, Daemon::$config->user->value);
			}
		}
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
			Daemon::$config->logtostderr 
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
			'~' . ($all ? '.' : '[^A-Za-z\d\.\{\}$:;\-_/\\\\]') . '~s',
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
