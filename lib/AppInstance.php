<?php

/**
 * Application instance
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class AppInstance {

	public $status = 0;        // runtime status
	public $passphrase;        // optional passphrase
	public $ready = FALSE;     // ready to start?
	public $name;              // name of instance
	public $config;
	public $enableRPC = false;
	public $requestClass;

	const EVENT_CONFIG_UPDATED = 1;
	const EVENT_GRACEFUL_SHUTDOWN = 2;
	const EVENT_HARD_SHUTDOWN = 3;
 
	/**	
	 * Application constructor
	 * @return void
	 */
	public function __construct($name = '') {
		$this->name = $name;
		$appName = get_class($this);
		$appNameLower = strtolower($appName);
		$fullname = Daemon::$appResolver->getAppFullName($appName, $this->name);
		//Daemon::$process->log($fullname . ' instantiated.');

		if ($this->requestClass === null) {
			$this->requestClass = get_class($this) . 'Request';
			if (!class_exists($this->requestClass)) {
				$this->requestClass = null;
			}
		}

		if (!isset(Daemon::$appInstances[$appNameLower])) {
			Daemon::$appInstances[$appNameLower] = [];
		}
		Daemon::$appInstances[$appNameLower][$this->name] = $this;
		
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
		if ($this->isEnabled()) {
			Daemon::$process->log($appName . ($name ? ":{$name}" : '') . ' up.');
		}

		$defaults = $this->getConfigDefaults();
		if ($defaults) {
			$this->processDefaultConfig($defaults);
		}

		$this->init();

		if (Daemon::$process instanceof Daemon_WorkerThread) {
			if (!$this->ready) {
				$this->ready = true;
				$this->onReady();
			}
		}
	}
	
	public static function getInstance($name) {
		return Daemon::$appResolver->getInstanceByAppName(get_called_class(), $name);
	}
	
	public function isEnabled() {
		return isset($this->config->enable->value) && $this->config->enable->value;
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
	 * Function handles incoming Remote Procedure Calls
	 * You can override it
	 * @param string Method name.
	 * @param array Arguments.
	 * @return mixed Result
	 */
	public function RPCall($method, $args) {
		if ($this->enableRPC && is_callable([$this, $method])) {
			return call_user_func_array([$this, $method], $args);
		}
	}
	
	
	/**
	 * Send broadcast RPC.
	 * You can override it
	 * @param string Method name.
	 * @param array Arguments.
	 * @param mixed Callback.
	 * @return boolean Success.
	 */
	public function broadcastCall($method, $args = [], $cb = NULL) {
		return Daemon::$process->IPCManager->sendBroadcastCall(
					get_class($this) . ($this->name !== '' ? ':' . $this->name : ''),
					$method,
					$args,
					$cb
		);
	}

	/**
	 * Send RPC, executed once in any worker.
	 * You can override it
	 * @param string Method name.
	 * @param array Arguments.
	 * @param mixed Callback.
	 * @return boolean Success.
	 */
	public function singleCall($method, $args = [], $cb = NULL) {
		return Daemon::$process->IPCManager->sendSingleCall(
					get_class($this) . ($this->name !== '' ? ':' . $this->name : ''),
					$method,
					$args,
					$cb
		);
	}

	/**
	 * Send RPC, executed once in certain worker.
	 * You can override it
	 * @param integer Worker Id.
	 * @param string Method name.
	 * @param array Arguments.
	 * @param mixed Callback.
	 * @return boolean Success.
	 */
	public function directCall($workerId, $method, $args = [], $cb = NULL) {
		return Daemon::$process->IPCManager->sendDirectCall(
					$workerId,
					get_class($this) . ($this->name !== '' ? ':' . $this->name : ''),
					$method,
					$args,
					$cb
		);
	}
	

 	/**
	 * Process default config
	 * @todo move it to Daemon_Config class
	 * @param array {"setting": "value"}
	 * @return void
	 */
	public function processDefaultConfig($settings = []) {
		foreach ($settings as $k => $v) {
			$k = strtolower(str_replace('-', '', $k));

			if (!isset($this->config->{$k})) {
			  if (is_scalar($v))	{
					$this->config->{$k} = new Daemon_ConfigEntry($v);
				} else {
					$this->config->{$k} = $v;
				}
			} elseif ($v instanceof Daemon_ConfigSection) {
			// @todo
			}	else {
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
	 * @todo -> protected?
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
	 * @todo call it only when application section config is changed
	 * @return void
	 */
	public function onConfigUpdated() {}
 
	/**
	 * Called when application instance is going to shutdown
	 * @todo protected?
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown($graceful = false) {
		return TRUE;
	}
 
	/**
	 * Create Request
	 * @todo more description needed
	 * @param object Request
	 * @param object Upstream application instance
	 * @return object Request
	 */
	public function beginRequest($req, $upstream) {
		if (!$this->requestClass) {
			return false;
		}
		$className = $this->requestClass;
		return new $className($this, $upstream, $req);
    }

	/**
	 * Log something
	 * @param string - Message.
	 * @return void
	 */
	public function log($message) {
		Daemon::log(get_class($this) . ': ' . $message);
	}
	
	/** 
	 * Shutdown the application instance
	 * @param boolean Graceful?
	 * @return void
	 */
	public function shutdown($graceful = false) {
		return $this->onShutdown($graceful);
	}
 
	/**
	 * Handle the request
	 * @param object Parent request
	 * @param object Upstream application
	 * @return object Request
	 */
	public function handleRequest($parent, $upstream) {
		$req = $this->beginRequest($parent, $upstream);

		if (!$req) {
			return $parent;
		}

		return $req;
	}
 
	/**
	 * Handle the worker status
	 * @param int Status code @todo use constants in method
	 * @return boolean Result
	 */
	public function handleStatus($ret) {
		if ($ret === self::EVENT_CONFIG_UPDATED) {
			// script update
			return  $this->onConfigUpdated();
		} elseif ($ret === self::EVENT_GRACEFUL_SHUTDOWN) {
			 // graceful worker shutdown for restart
			return $this->shutdown(true);
		} elseif ($ret === self::EVENT_HARD_SHUTDOWN) {
			// shutdown worker
			return $this->shutdown();
		} else {
			return true;
		}
	}
}
