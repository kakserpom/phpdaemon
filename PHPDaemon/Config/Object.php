<?php
namespace PHPDaemon\Config;

use PHPDaemon\Config\Entry\ConfigFile;
use PHPDaemon\Config\Entry\Number;
use PHPDaemon\Config\Entry\Size;
use PHPDaemon\Config\Entry\Time;
use PHPDaemon\Config\Parser;
use PHPDaemon\Core\Daemon;

/**
 * Config class
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 * @dynamic_fields
 */
class Object implements \ArrayAccess {
	use \PHPDaemon\Traits\ClassWatchdog;

	/**
	 * Maximum memory usage
	 * @var size
	 */
	public $maxmemoryusage = '0b';

	/**
	 * Maximum idle time
	 * @var time
	 */
	public $maxidle = '0s';

	/**
	 * PID file
	 * @var string|Entry\Generic
	 */
	public $pidfile = '/var/run/phpd.pid';

	/**
	 * Default namespace
	 * @var path
	 */
	public $defaultns = 'PHPDaemon';

	/**
	 * Default PID file
	 * @var path
	 */
	public $defaultpidfile = '/var/run/phpd.pid';

	/**
	 * Config file
	 * @var string|Entry\ConfigFile
	 */
	public $configfile = '/etc/phpdaemon/phpd.conf;/etc/phpd/phpd.conf;./conf/phpd.conf';

	/**
	 * Application resolver
	 * @var string|Entry\Generic
	 */
	public $path = '/etc/phpdaemon/AppResolver.php;./conf/AppResolver.php';

	/**
	 * Additional include path
	 * @var string|Entry\Generic
	 */
	public $addincludepath = null;

	/**
	 * Multi-Process Manager delay
	 * @var time
	 */
	public $mpmdelay = '0.1s';

	/**
	 * Max. requests before worker restart
	 * @var int|Entry\Number
	 */
	public $maxrequests = '10k';

	/**
	 * Start workers
	 * @var int|Entry\Number
	 */
	public $startworkers = 4;

	/**
	 * Minimum number of workers
	 * @var int|Entry\Number
	 */
	public $minworkers = 4;

	/**
	 * Maximum number of workers
	 * @var int|Entry\Number
	 */
	public $maxworkers = 8;

	/**
	 * Minimum number of spare workes
	 * @var int|Entry\Number
	 */
	public $minspareworkers = 2;

	/**
	 * Maximum number of spare workes
	 * @var int|Entry\Number
	 */
	public $maxspareworkers = 0;

	/**
	 * Master thread priority
	 * @var integer
	 */
	public $masterpriority = 100;

	/**
	 * IPC thread priority
	 * @var integer
	 */
	public $ipcthreadpriority = 100;

	/**
	 * IPC thread priority
	 * @var boolean
	 */
	public $obfilterauto = 1;

	/**
	 * System user (setuid)
	 * @var string|Entry\Generic
	 */
	public $user = null;

	/**
	 * System group (setgid)
	 * @var string|Entry\Generic
	 */
	public $group = null;

	/**
	 * Automatic garbage collector, number of iterations between GC call
	 * @var int|Entry\Number
	 */
	public $autogc = '1k';

	/**
	 * Chroot
	 * @var string|Entry\Generic
	 */
	public $chroot = '/';

	/**
	 * Current directory
	 * @var string
	 */
	public $cwd = '.';

	/**
	 * Autoreload interval. Time interval between checks.
	 * @var time
	 */
	public $autoreload = '0s';

	/**
	 * Try to import updated code (runkit required)
	 * @var boolean|Entry\Generic
	 */
	public $autoreimport = 0;

	/**
	 * Worker thread priority
	 * @var integer
	 */
	public $workerpriority = 4;

	/**
	 * Lambda cache size
	 * @var integer
	 */
	public $lambdacachemaxsize = 128;

	/**
	 * Lambda cache cap window
	 * @var integer
	 */
	public $lambdacachecapwindow = 32;

	/**
	 * Lambda cache ttl
	 * @var integer
	 */
	public $lambdacachettl = 0;

	/**
	 * Throw exception on shutdown?
	 * @var boolean
	 */
	public $throwexceptiononshutdown = 0;

	/**
	 * Comma-separated list of locales
	 * @var string|Entry\Generic
	 */
	public $locale = '';

	/**
	 * Restrict usage of error-control functions (like @ operator), useful in debugging
	 * @var boolean
	 */
	public $restricterrorcontrol = false;

	// Logging-related

	/**
	 * Logging?
	 * @var boolean
	 */
	public $logging = 1;

	/**
	 * Log storage
	 * @var boolean|Entry\Generic
	 */
	public $logstorage = '/var/log/phpdaemon.log';

	/**
	 * Log format
	 * @var string
	 */
	public $logformat = '[D, j M Y H:i:s.u O] %msg%';

	/**
	 * Log errors?
	 * @var boolean
	 */
	public $logerrors = 1;

	/**
	 * Log Worker->setState() ?
	 * @var boolean
	 */
	public $logworkersetstate = 0;

	/**
	 * Log events?
	 * @var boolean
	 */
	public $logevents = 0;

	/**
	 * Log signals?
	 * @var boolean
	 */
	public $logsignals = 0;

	/**
	 * Do not close STDOUT and STDERR pipes and send log messages there
	 * @var boolean
	 */
	public $verbosetty = 0;

	/**
	 * EIO enabled?
	 * @var boolean
	 */
	public $eioenabled = 1;

	/**
	 * EIO maximum idle time
	 * @var time
	 */
	public $eiosetmaxidle = null;

	/**
	 * EIO maximum parallel threads
	 * @var int|Entry\Number
	 */
	public $eiosetmaxparallel = null;

	/**
	 * EIO maximum poll requests
	 * @var int|Entry\Number
	 */
	public $eiosetmaxpollreqs = null;

	/**
	 * EIO maximum poll time
	 * @var time
	 */
	public $eiosetmaxpolltime = null;

	/**
	 * EIO minimum parallel threads
	 * @var int|Entry\Number
	 */
	public $eiosetminparallel = null;

	/** @var int */
	public static $lastRevision = 0;

	/**
	 * Constructor
	 * @return object
	 */

	public function __construct() {
		static $sizes = ['maxmemoryusage'];
		static $times = ['maxidle', 'autoreload', 'mpmdelay', 'eiosetmaxpolltime', 'lambdacachettl'];
		static $numbers = [
			'maxrequests', 'autogc', 'startworkers', 'workerpriority', 'minworkers', 'maxworkers', 'minspareworkers', 'maxspareworkers', 'masterpriority', 'ipcthreadpriority',
			'eiosetmaxidle', 'eiosetmaxparallel', 'eiosetmaxpollreqs', 'eiosetminparallel', 'verbose', 'verbosetty',
			'lambdacachemaxsize', 'lambdacachecapwindow',
		];

		foreach ($this as $name => $value) {
			if (in_array($name, $sizes)) {
				$entry = new Size;
			}
			elseif (in_array($name, $times)) {
				$entry = new Time;
			}
			elseif (in_array($name, $numbers)) {
				$entry = new Number;
			}
			elseif ($name === 'configfile') {
				$entry = new ConfigFile;
			}
			else {
				$entry = new Entry\Generic;
			}

			if ($name === 'addincludepath') {
				$entry->setStackable();
			}

			$entry->setDefaultValue($value);
			$entry->setHumanValue($value);
			$this->{$name} = $entry;
		}
	}

	/**
	 * Renames section
	 * @param string $old
	 * @param string $new
	 * @param booelan $log Log?
	 * @return void
	 */
	public function renameSection($old, $new, $log = false) {
		Daemon::$config->{$new} = Daemon::$config->{$old};
		unset(Daemon::$config->{$old});
		if ($log) {
			Daemon::log('Config section \'' . $old . '\' -> \'' . $new . '\'');
		}		
	}

	/**
	 * Load config file
	 * @param string Path
	 * @return boolean Success
	 */
	public function loadFile($path) {
		$parser = Parser::parse($path, $this);
		$this->onLoad();
		return !$parser->isErroneous();
	}

	/**
	 * Called when config is loaded
	 * @return void
	 */
	protected function onLoad() {
		if (
				isset($this->minspareworkers->value)
				&& $this->minspareworkers->value > 0
				&& isset($this->maxspareworkers->value)
				&& $this->maxspareworkers->value > 0
		) {
			if ($this->minspareworkers->value > $this->maxspareworkers->value) {
				Daemon::log('\'minspareworkers\' (' . $this->minspareworkers->value . ')  cannot be greater than \'maxspareworkers\' (' . $this->maxspareworkers->value . ').');
				$this->minspareworkers->value = $this->maxspareworkers->value;
			}
		}

		if (
				isset($this->minworkers->value)
				&& isset($this->maxworkers->value)
		) {
			if ($this->minworkers->value > $this->maxworkers->value) {
				$this->minworkers->value = $this->maxworkers->value;
			}
		}
	}

	/**
	 * Get real property name
	 * @param string Property name
	 * @return string Real property name
	 */
	public function getRealPropertyName($prop) {
		return str_replace('-', '', strtolower($prop));
	}

	/**
	 * Checks if property exists
	 * @param string Property name
	 * @return boolean Exists?
	 */

	public function offsetExists($prop) {
		$prop = $this->getRealPropertyName($prop);
		return property_exists($this, $prop);
	}

	/**
	 * Get property by name
	 * @param string Property name
	 * @return mixed
	 */
	public function offsetGet($prop) {
		$prop = $this->getRealPropertyName($prop);
		return isset($this->{$prop}) ? $this->{$prop}->value : null;
	}

	/**
	 * Set property
	 * @param string Property name
	 * @param mixed  Value
	 * @return void
	 */
	public function offsetSet($prop, $value) {
		$prop          = $this->getRealPropertyName($prop);
		$this->{$prop} = $value;
	}

	/**
	 * Unset property
	 * @param string Property name
	 * @return void
	 */
	public function offsetUnset($prop) {
		$prop = $this->getRealPropertyName($prop);
		unset($this->{$prop});
	}

	/**
	 * Checks if property exists
	 * @param string Property name
	 * @return boolean Exists?
	 */
	public static function parseCfgUri($uri, $source = null) {
		if (strpos($uri, '://') === false) {
			if (strncmp($uri, 'unix:', 5) === 0) {
				$e = explode(':', $uri);
				if (sizeof($e) === 4) {
					$uri = 'unix://' . $e[1] . ':' . $e[2] . '@localhost' . $e[3];
				}
				elseif (sizeof($e) === 3) {
					$uri = 'unix://' . $e[1] . '@localhost' . $e[2];
				}
				else {
					$uri = 'unix://localhost' . $e[1];
				}
			}
			else {
				$uri = 'tcp://' . $uri;
			}
		}
		if (stripos($uri, 'unix:///') === 0) {
			$uri = 'unix://localhost/' . substr($uri, 8);
		}
		$zeroPortNum = false;
		$uri = preg_replace_callback('~:0(?:$|/)~', function() use (&$zeroPortNum) {
			$zeroPortNum = true;
			return '';
		}, $uri);
		$u        = parse_url($uri);
		$u['uri'] = $uri;
		if ($zeroPortNum) {
			$u['port'] = 0;
		}
		if (!isset($u['scheme'])) {
			$u['scheme'] = '';
		}
		$u['params'] = [];
		if (!isset($u['fragment'])) {
			return $u;
		}
		$hash  = '#' . $u['fragment'];
		$error = false;
		preg_replace_callback('~(#+)(.+?)(?=#|$)|(.+)~', function ($m) use (&$u, &$error, $uri) {
			if ($error) {
				return;
			}
			list (, $type, $value) = $m;
			if ($type === '#') { // standard value
				$e = explode('=', $value, 2);
				if (sizeof($e) === 2) {
					list ($key, $value) = $e;
				}
				else {
					$key   = $value;
					$value = true;
				}
				$u['params'][$key] = $value;
			}
			elseif ($type === '##') { // Context name
				$u['params']['ctxname'] = $value;
			}
			else {
				Daemon::log('Malformed URI: ' . var_export($uri, true) . ', unexpected token \'' . $type . '\'');
				$error = true;
			}
		}, $hash);
		return $error ? false : $u;
	}

	/**
	 * Imports parameters from command line args
	 * @param array Settings.
	 * @return boolean - Success.
	 */
	public static function loadCmdLineArgs($settings) {
		$error = FALSE;

		static $ktr = [
			'-' => '',
		];

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
				Daemon::$config->{$k}->setHumanValue($v);
				Daemon::$config->{$k}->source = 'cmdline';

			}
			else {
				Daemon::log('Unrecognized parameter \'' . $k . '\'');
				$error = TRUE;
			}
		}

		return !$error;
	}

}
