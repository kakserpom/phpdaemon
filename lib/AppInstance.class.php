<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class AppInstance
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description AppInstance class.
/**************************************************************************/

class AppInstance {

	public $status = 0;        // runtime status
	public $passphrase;        // optional passphrase
	public $reqCounter = 0;    // counter of requests
	public $queue = array();   // queue of requests
	public $ready = FALSE;     // ready to start?
	public $name;              // name of instance
	public $config;
 
	/**	
	 * Application constructor
	 * @return void
	 */
	public function __construct($name = '') {
		$this->name = $name;
		$fullname = get_class($this) . ($this->name !== '' ? '-' . $this->name : '');
		
		if (!isset(Daemon::$config->{$fullname})) {
			Daemon::$config->{$fullname} = new Daemon_ConfigSection;
		} else {
			if (
				!isset(Daemon::$config->{$fullname}->enable)
				&& !isset(Daemon::$config->{$fullname}->disable)
			) {
				Daemon::$config->{$fullname}->enable = new Daemon_ConfigEntry;
				Daemon::$config->{$fullname}->enable->setValue(TRUE);
			}
		}

		$this->config = Daemon::$config->{$fullname};

		$defaults = $this->getConfigDefaults();
		if ($defaults) {
			$this->processDefaultConfig($defaults);
		}

		$this->init();

		if (Daemon::$worker) {
			$this->onReady();
			$this->ready = TRUE;
		}
	}

	/**
	 * Function to get default config options from application
	 * Override to set your own
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return false;
	}

 	/**
	 * Process default config
	 * FIXME move it to Daemon_Config class
	 * @param array {"setting": "value"}
	 * @return void
	 */
	private function processDefaultConfig($settings = array()) {
		foreach ($settings as $k => $v) {
			$k = strtolower(str_replace('-', '', $k));

			if (!isset($this->config->{$k})) {
			  if (is_scalar($v))	{
					$this->config->{$k} = new Daemon_ConfigEntry($v);
				} else {
					$this->config->{$k} = $v;
				}
			} else {
				$current = $this->config->{$k};
			  if (is_scalar($v))	{
					$this->config->{$k} = new Daemon_ConfigEntry($v);
				} else {
					$this->config->{$k} = $v;
				}
				
				$this->config->{$k}->setHumanValue($current->value);
				$this->config->{$k}->source = $current->source;
				$this->config->{$k}->revision = $current->revision;
			}
		}
	}
	
	/**
	 * Called when the worker is ready to go
	 * FIXME -> protected?
	 * @return void
	 */
	public function onReady() { }
 
	/**
	 * Called when creates instance of the application
	 * @return void
	 */
	public function init() {}
 
	/**
	 * Called when worker is going to update configuration
	 * FIXME call it only when application section config is changed
	 * FIXME rename to onConfigChanged()
	 * @return void
	 */
	public function update() {}
 
	/**
	 * Called when application instance is going to shutdown
	 * FIXME protected?
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown() {
		return TRUE;
	}
 
	/**
	 * Create Request
	 * FIXME more description needed
	 * @param object Request
	 * @param object Upstream application instance
	 * @return object Request
	 */
	public function beginRequest($req, $upstream) {
		return FALSE;
	}
 
	/**
	 * Handles the output from downstream requests
	 * FIXME more description
	 * @param object Request
	 * @param string The output
	 * @return void
	 */
	public function requestOut($r, $s) { }
 
	/**
	 * Handles the output from downstream requests
	 * @return void
	 */
	public function endRequest($req, $appStatus, $protoStatus) { }
 
	/** 
	 * Shutdown the application instance
	 * @param boolean Graceful?
	 * @return void
	 */
	public function shutdown($graceful = false) {
		if (Daemon::$config->logevents->value) {
			Daemon::log(__METHOD__ . ' invoked. Size of the queue: ' . sizeof($this->queue) . '.');
		}

		foreach ($this->queue as &$r) {
			if ($r instanceof stdClass) {
				continue;
			}
			
			$r->finish();
		}

		return $this->onShutdown();
	}
 
	/**
	 * Handle the request
	 * @param object Parent request
	 * @param object Upstream application  FIXME is upstream really needed?
	 * @return object Request
	 */
	public function handleRequest($parent, $upstream) {
		$req = $this->beginRequest($parent, $upstream);

		if (!$req) {
			return $parent;
		}

		if (Daemon::$config->logqueue->value) {
			Daemon::$worker->log('request added to ' . get_class($this) . '->queue.');
		}

		return $req;
	}
 
	/**
	 * Pushes request to the queue
	 * FIXME log warning message and then sometime remove it
	 * @param object Request
	 * @return object Request
	 * @deprecated
	 */
	public function pushRequest($req) {
		return $req;
	}
 
	/**
	 * Handle the worker status
	 * @param int Status code FIXME use constants in method
	 * @return boolean Result
	 */
	public function handleStatus($ret) {
		if ($ret === 2) {
			// script update
			$r = $this->update();
		}
		elseif ($ret === 3) {
			 // graceful worker shutdown for restart
			$r = $this->shutdown(TRUE);
		}
		elseif ($ret === 5) {
			// shutdown worker
			$r = $this->shutdown();
		} else {
			$r = TRUE;
		}

		return $r;
	}

}
