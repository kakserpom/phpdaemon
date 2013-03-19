<?php

/**
 * Config class
 *
 * @package Core
 * @subpackage Config
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon_Config implements ArrayAccess {

	// Worker graceful restarting:
	public $maxmemoryusage = '0b';
	public $maxidle        = '0s';
			
	// Main Pathes
	public $pidfile        = '/var/run/phpd.pid';
	public $defaultpidfile = '/var/run/phpd.pid';
	public $configfile     = '/etc/phpdaemon/phpd.conf;/etc/phpd/phpd.conf;./conf/phpd.conf';
	public $path           = '/etc/phpdaemon/AppResolver.php;./conf/AppResolver.php';
	public $appfilepath    = '{app-*,applications}/%s.php';
	public $autoload   	 	= NULL;
			
	// Master-related
	public $mpmdelay        = '0.1s';
	public $startworkers    = 4;
	public $minworkers      = 4;
	public $maxworkers      = 8;
	public $minspareworkers = 2;
	public $maxspareworkers = 0;
	public $masterpriority  = 100;
	public $ipcthreadpriority = 100;
			 
	// Requests
	public $obfilterauto                   = 1;
	public $maxconcurrentrequestsperworker = 1000;
			
	// Worker-related
	public $user                     = NULL;
	public $group                    = NULL;
	public $autogc                   = '1k';
	public $chroot                   = '/';
	public $cwd                      = '.';
	public $autoreload               = '0s';
	public $autoreimport             = 0;
	public $workerpriority           = 4;
	public $throwexceptiononshutdown = 0;
	public $locale                   = '';
	public $restricterrorcontrol = false; 
			
	// Logging-related
	public $logging            = 1;
	public $logstorage         = '/var/log/phpdaemon.log';
	public $logerrors          = 1;
	public $logworkersetstate = 0;
	public $logevents          = 0;
	public $logqueue           = 0;
	public $logreads           = 0;
	public $logsignals         = 0;
	public $verbose = 0;
	public $verbosetty = 0;
	
	// eio
	public $eioenabled = 1;
	public $eiosetmaxidle = null;
	public $eiosetmaxparallel = null;
	public $eiosetmaxpollreqs = null;
	public $eiosetmaxpolltime = null;
	public $eiosetminparallel = null;
	
	public static $lastRevision = 0;
	
	// @todo phpdoc missed
	
	public function __construct() {
		static $sizes = array('maxmemoryusage');
		static $times = array('maxidle', 'autoreload', 'mpmdelay', 'eiosetmaxpolltime');
		static $numbers = array(
			'maxrequests', 'autogc','minworkers','maxworkers','minspareworkers','maxspareworkers','masterpriority', 'ipcthreadpriority',
			'eiosetmaxidle', 'eiosetmaxparallel', 'eiosetmaxpollreqs', 'eiosetminparallel', 'verbose', 'verbosetty'
		);

		foreach ($this as $name => $value) {
			if (in_array($name, $sizes)) {
				$entry = new Daemon_ConfigEntrySize;
			}
			elseif (in_array($name, $times)) {
				$entry = new Daemon_ConfigEntryTime;
			}
			elseif (in_array($name, $numbers)) {
				$entry = new Daemon_ConfigEntryNumber;
			} 
			elseif ($name === 'configfile') {
				$entry = new Daemon_ConfigEntryConfigFile;
			} else {
				$entry = new Daemon_ConfigEntry;
			}
			
			$entry->setDefaultValue($value);
			$entry->setHumanValue($value);
			$this->{$name} = $entry;
		}
	}

	public function loadFile($path) {
		$parser = new Daemon_ConfigParser($path,$this);
		$this->onLoad();
		return !$parser->isErrorneous();
	}
	
	protected function onLoad() {
		if (
			isset($this->minspareworkers->value) 
			&& $this->minspareworkers->value > 0
			&& isset($this->maxspareworkers->value)
			&& $this->maxspareworkers->value > 0
		) {
			if ($this->minspareworkers->value > $this->maxspareworkers->value) {
				Daemon::log('\'minspareworkers\' ('.$this->minspareworkers->value.')  cannot be greater than \'maxspareworkers\' ('.$this->maxspareworkers->value.').');
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

	public function getRealOffsetName($offset) {
		return str_replace('-', '', strtolower($offset));
	}
	
	public function offsetExists($offset) {
		return $this->offsetGet($offset) !== NULL;
	}

	public function offsetGet($offset) {
		$offset = $this->getRealOffsetName($offset);

		return isset($this->{$offset}) ? $this->{$offset}->value : NULL;
	}
	
	public function offsetSet($offset,$value) {
		$offset = $this->getRealOffsetName($offset);

		$this->{$offset} = $value;
	}

	public function offsetUnset($offset) {
		unset($this->{$this->getRealOffsetName($offset)});
	}

	public static function parseCfgUri($uri, $source = null) {
		if (strpos($uri, '://') === false) {
			if (strncmp($uri, 'unix:', 5) === 0) {
				$e = explode(':', $uri);
				if (sizeof($e) === 4) {
					$uri = 'unix://'.$e[1].':'.$e[2].'@localhost'.$e[3];
				} elseif (sizeof($e) === 3) {
					$uri = 'unix://'.$e[1].'@localhost'.$e[2];
				} else {
					$uri = 'unix://localhost'.$e[1];
				}
			} else {
				$uri = 'tcp://' . $uri;
			}
		}
		if (stripos($uri, 'unix:///') === 0) {
			$uri = 'unix://localhost/' . substr($uri, 8);
		}
		$u = parse_url($uri);
		$u['uri'] = $uri;
		if (!isset($u['scheme'])) {
			$u['scheme'] = '';
		}
		$u['params'] = [];
		if (!isset($u['fragment'])) {
			return $u;
		}
		$hash = '#' . $u['fragment'];
		$error = false;
		preg_replace_callback('~(#+)(.+?)(?=#|$)|(.+)~', function($m) use (&$u, &$error, $uri) { // @TODO: refactoring
			if ($error) {
				return;
			}
			list (, $type, $value) = $m;
			if ($type === '#') { // standard value
				$e = explode('=', $value, 2);
				if (sizeof($e) === 2) {
					list ($key, $value) = $e;
				} else {
					$key = $value;
					$value = true;
				}
				$u['params'][$key] = $value;
			} elseif ($type === '##') { // Context name
				$u['params']['ctxname'] = $value;
			} else {
				Daemon::log('Malformed URI: '.var_export($uri, true).', unexpected token \'' . $type . '\'');
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
