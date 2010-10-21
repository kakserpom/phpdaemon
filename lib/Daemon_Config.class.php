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
	public $maxrequests    = '1k';
	public $maxmemoryusage = '0b';
	public $maxidle        = '0s';
			
	// Main Pathes
	public $pidfile        = '/var/run/phpd.pid';
	public $defaultpidfile = '/var/run/phpd.pid';
	public $configfile     = '/etc/phpd/phpd.conf;./conf/phpd.conf';
	public $path           = './conf/appResolver.php';
	public $ipcwstate      = '/var/run/phpdaemon-wstate.ipc';
	public $appfilepath    = '{app-*,applications}/%s.php';
	public $autoload   	 	= NULL;
			
	// Master-related
	public $mpmdelay        = '1s';
	public $startworkers    = 20;
	public $minworkers      = 20;
	public $maxworkers      = 80;
	public $minspareworkers = 20;
	public $maxspareworkers = 50;
	public $masterpriority  = 100;
			 
	// Requests
	public $obfilterauto                   = 1;
	public $maxconcurrentrequestsperworker = 1000;
			
	// Worker-related
	public $user                     = NULL;
	public $group                    = NULL;
	public $autogc                   = '1';
	public $chroot                   = '/';
	public $cwd                      = '.';
	public $autoreload               = '0s';
	public $autoreimport             = 0;
	public $workerpriority           = 4;
	public $throwexceptiononshutdown = 0;
	public $locale                   = '';
			
	// Logging-related
	public $logging            = 1;
	public $logtostderr        = 1;
	public $logstorage         = '/var/log/phpdaemon.log';
	public $logerrors          = 1;
	public $logworkersetstatus = 0;
	public $logevents          = 0;
	public $logqueue           = 0;
	public $logreads           = 0;
	public $logsignals         = 0;
	
	public static $lastRevision = 0;
	
	// @todo phpdoc missed
	
	public function __construct() {
		static $sizes = array('maxmemoryusage');
		static $times = array('maxidle', 'autoreload', 'mpmdelay');
		static $numbers = array('maxrequests', 'autogc');

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
		if (!$parser->errorneus) {
			if (Daemon::$process instanceof Daemon_MasterThread) {
				Daemon::$process->updatedWorkers();
			}
		}
		return !$parser->errorneus;
	}

	public function getRealOffsetName($offset) {
		return str_replace('-', '', strtolower($offset));
	}
	
	public function offsetExists($offset) {
		return $this->offsetGet($offset) !== NULL;
	}

	public function offsetGet($offset) {
		$offset = $this->getRealOffsetName($offset);

		if (substr($offset, 0, 4) == 'mod-') {
			$e = explode('-', $offset, 3);
			$k = $e[1];

			if (!isset($this->{$k})) {
				$this->{$k} = new DaemonConfigSection;
			}

			if ($this->{$k} instanceof Daemon_ConfigSection) {
					$this->{$k}->{$e[2]} = $value;
			}

			return;
		}

		return isset($this->{$offset}) ? $this->{$offset}->value : NULL;
	}
	
	public function offsetSet($offset,$value) {
		$offset = $this->getRealOffsetName($offset);

		if (substr($offset, 0, 4) == 'mod-') {
			$e = explode('-', $offset, 3);
			$k = $e[1];
			
			if (!isset($this->{$k})) {
				$this->{$k} = new DaemonConfigSection;
			}

			if ($this->{$k} instanceof Daemon_ConfigSection) {
					$this->{$k}->{$e[2]} = $value;
			}

			return;
		}

		$this->{$offset} = $value;
	}

	public function offsetUnset($offset) {
		unset($this->{$this->getRealOffsetName($offset)});
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
