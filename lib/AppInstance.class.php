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
	public $name;							// name of instance
	public $config;
 
	/**	
	 * @method __contruct
	 * @description Application constructor.
	 * @return void
	 */
	public function __construct($name = NULL) {
		$this->name = $name;
		$fullname = get_class($this).($this->name !== NULL ? '-'.urlencode($this->name) : '');

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
		$this->init();

		if (Daemon::$worker) {
			$this->onReady();
			$this->ready = TRUE;
		}
	}

 	/**
	 * @method defaultConfig
	 * @param array {"setting": "value"}
	 * @description Adds default settings to repository.
	 * @return boolean - Succes.
	 */
	public function defaultConfig($settings = array()) {
		foreach ($settings as $k => $v) {
			$k = strtolower(str_replace('-', '', $k));

			if (!isset($this->config->{$k})) {
			  if (is_scalar($v))	{
					$this->config->{$k} = new Daemon_ConfigEntry($v);
				}
				else {
					$this->config->{$k} = $v;
				}
			}
			else {
				$current = $this->config->{$k};
			  if (is_scalar($v))	{
					$this->config->{$k} = new Daemon_ConfigEntry($v);
				}
				else {
					$this->config->{$k} = $v;
				}
				$this->config->{$k}->setHumanValue($current->value);
				$this->config->{$k}->source = $current->source;
				$this->config->{$k}->revision = $current->revision;
			}
		}

		return TRUE;
	}
	
	/**
	 * @method onReady
	 * @description Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {}
 
	/**
	 * @method init
	 * @description Called when creates instance of the application.
	 * @return void
	 */
	public function init() {}
 
	/**
	 * @method update
	 * @description Called when worker is going to update configuration.
	 * @return void
	 */
	public function update() {}
 
	/**
	 * @method onShutdown
	 * @description Called when application instance is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown() {
		return TRUE;
	}
 
	/**
	 * @method beginRequest
	 * @description Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return FALSE;
	}
 
	/**
	 * @method requestOut
	 * @description Handles the output from downstream requests.
	 * @param object Request.
	 * @param string The output.
	 * @return void
	 */
	public function requestOut($r, $s) {}
 
	/**
	 * @method endRequest
	 * @description Handles the output from downstream requests.
	 * @return void
	 */
	public function endRequest($req, $appStatus, $protoStatus) {}
 
	/** 
	 * @method shutdown
	 * @param boolean Graceful.
	 * @description Shutdowns the application instance.
	 * @return void
	 */
	public function shutdown($graceful = FALSE) {
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
	 * @method handleRequest
	 * @param object Parent request.
	 * @param object Upstream application.
	 * @description Handles the request.
	 * @return object Request.
	 */
	public function handleRequest($parent, $upstream) {
		$req = $this->beginRequest($parent, $upstream);

		if (!$req) {
			return $parent;
		}

		$req->idAppQueue = ++$this->reqCounter;

		if (Daemon::$config->logqueue->value) {
			Daemon::$worker->log('request added to ' . get_class($this) . '->queue.');
		}

		$this->queue[$req->idAppQueue] = $req;

		return $req;
	}
 
	/**
	 * @method pushRequest
	 * @param object Request.
	 * @description Pushes request to the queue.
	 * @return object Request.
	 */
	public function pushRequest($req) {
		$req->idAppQueue = ++$this->reqCounter;
		$this->queue[$req->idAppQueue] = $req;
		Daemon::$worker->queue[get_class($this) . '-' . $req->idAppQueue] = $req;

		return $req;
	}
 
	/**
	 * @method handleStatus
	 * @param int Status code.
	 * @description Handles the worker's status.
	 * @return boolean Result.
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
