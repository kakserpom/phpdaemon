<?php

/**
 * Application instance
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class AppInstance {
	public $passphrase;        // optional passphrase
	public $ready = false;     // ready to start?
	protected $name;           // name of instance
	public $config;
	public $enableRPC = false;
	public $requestClass;
	public static $runOnDemand = true;

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
				Daemon::$config->{$fullname}->enable->setValue(true);
			}
		}

		$this->config = Daemon::$config->{$fullname};
		if ($this->isEnabled()) {
			Daemon::$process->log($appName . ($name ? ":{$name}" : '') . ' up.');
		}

		$defaults = $this->getConfigDefaults();
		if ($defaults) {
			$this->config->imposeDefault($defaults);
		}

		$this->init();

		if (Daemon::$process instanceof Daemon_WorkerThread) {
			if (!$this->ready) {
				$this->ready = true;
				$this->onReady();
			}
		}
	}
	
	public static function getInstance($name, $spawn = true) {
		return Daemon::$appResolver->getInstanceByAppName(get_called_class(), $name, $spawn);
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
		if (!$this->enableRPC || !is_callable([$this, $method])) {
			return false;
		}
		return call_user_func_array([$this, $method], $args);
	}
	
	public function getConfig() {
		return $this->config;	
	}

	public function getName() {
		return $this->name;
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
	 * Called when the worker is ready to go
	 * @return void
	 */
	protected function onReady() {}
 
	/**
	 * Called when creates instance of the application
	 * @return void
	 */
	protected function init() {}
 
	/**
	 * Called when worker is going to update configuration
	 * @todo call it only when application section config is changed
	 * @return void
	 */
	public function onConfigUpdated() {}
 
	/**
	 * Called when application instance is going to shutdown
	 * @return boolean Ready to shutdown?
	 */
	protected function onShutdown($graceful = false) {
		return true;
	}
 
	/**
	 * Create Request instance
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
	 * Handle the request
	 * @param object Parent request
	 * @param object Upstream application
	 * @return object Request
	 */
	public function handleRequest($parent, $upstream) {
		$req = $this->beginRequest($parent, $upstream);
		return $req ?: $parent;
	}
 
	/**
	 * Handle the worker status
	 * @param int Status code
	 * @return boolean Result
	 */
	public function handleStatus($ret) {
		if ($ret === self::EVENT_CONFIG_UPDATED) {
			$this->onConfigUpdated();
			return true;
		} elseif ($ret === self::EVENT_GRACEFUL_SHUTDOWN) {
			return $this->onShutdown(true);
		} elseif ($ret === self::EVENT_HARD_SHUTDOWN) {
			return $this->onShutdown();
		}
		return false;
	}
}
