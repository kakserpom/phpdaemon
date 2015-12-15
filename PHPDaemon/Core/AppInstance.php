<?php
namespace PHPDaemon\Core;

use PHPDaemon\Config;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Request\Generic;
use PHPDaemon\Thread;

/**
 * Application instance
 * @package PHPDaemon\Core
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class AppInstance {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * Event: config updated
	 */
	const EVENT_CONFIG_UPDATED = 1; 
	
	/**
	 * Event: graceful shutdown
	 */
	const EVENT_GRACEFUL_SHUTDOWN = 2;
	
	/**
	 * Event: shutdown
	 */
	const EVENT_SHUTDOWN = 3;
	
	/**
	 * @var boolean If true, it's allowed to be run without defined config section'
	 */
	public static $runOnDemand = true;
	
	/**
	 * @var string Optional passphrase
	 */
	public $passphrase;
	
	/**
	 * @var boolean Ready to run?
	 */
	public $ready = false;
	
	/**
	 * @var object Related config section
	 */
	public $config;
	
	/**
	 * @var boolean Is RPC enabled?
	 */
	public $enableRPC = false;
	
	/**
	 * @var null|string Default class of incoming requests
	 */
	public $requestClass;
	
	/**
	 * @var string Instance name
	 */
	protected $name;

	/**
	 * Application constructor
	 * @param  string $name Instance name
	 * @return void
	 */
	public function __construct($name = '') {
		$this->name = $name;
		$appName    = '\\' . get_class($this);
		$fullname   = Daemon::$appResolver->getAppFullName($appName, $this->name);
		//Daemon::$process->log($fullname . ' instantiated.');

		if ($this->requestClass === null) {
			$this->requestClass = get_class($this) . 'Request';
			if (!class_exists($this->requestClass)) {
				$this->requestClass = null;
			}
		}

		if (!isset(Daemon::$appInstances[$appName])) {
			Daemon::$appInstances[$appName] = [];
		}
		Daemon::$appInstances[$appName][$this->name] = $this;

		if (!isset(Daemon::$config->{$fullname})) {

			Daemon::$config->{$fullname} = new Config\Section;
		}
		else {
			if (
					!isset(Daemon::$config->{$fullname}->enable)
					&& !isset(Daemon::$config->{$fullname}->disable)
			) {
				Daemon::$config->{$fullname}->enable = new Config\Entry\Generic;
				Daemon::$config->{$fullname}->enable->setValue(true);
			}
		}

		$this->config = Daemon::$config->{$fullname};
		if ($this->isEnabled()) {
			Daemon::$process->log($appName . ($name ? ":{$name}" : '') . ' up.');
		}

		$defaults = $this->getConfigDefaults();
		if ($defaults) {
			/** @noinspection PhpUndefinedMethodInspection */
			$this->config->imposeDefault($defaults);
		}

		$this->init();

		if (Daemon::$process instanceof Thread\Worker) {
			if (!$this->ready) {
				$this->ready = true;
				$this->onReady();
			}
		}
	}

	/**
	 * Returns whether if this application is enabled
	 * @return boolean
	 */
	public function isEnabled() {
		return isset($this->config->enable->value) && $this->config->enable->value;
	}

	/**
	 * Function to get default config options from application
	 * Override to set your own
	 * @return boolean
	 */
	protected function getConfigDefaults() {
		return false;
	}

	/**
	 * Called when creates instance of the application
	 * @return void
	 */
	protected function init() {
	}

	/**
	 * Called when the worker is ready to go
	 * @return void
	 */
	public function onReady() {
	}

	/**
	 * @param  string  $name  Instance name
	 * @param  boolean $spawn If true, we spawn an instance if absent
	 * @return AppInstance
	 */
	public static function getInstance($name, $spawn = true) {
		return Daemon::$appResolver->getInstanceByAppName('\\' . get_called_class(), $name, $spawn);
	}

	/**
	 * Function handles incoming Remote Procedure Calls
	 * You can override it
	 * @param  string $method Method name
	 * @param  array  $args   Arguments
	 * @return mixed          Result
	 */
	public function RPCall($method, $args) {
		if (!$this->enableRPC || !is_callable([$this, $method])) {
			return false;
		}
		return call_user_func_array([$this, $method], $args);
	}

	/**
	 * Returns a config section
	 * @return Config\Section
	 */
	public function getConfig() {
		return $this->config;
	}

	/**
	 * Returns the instance name
	 * @return string
	 */
	public function getName() {
		return $this->name;
	}

	/**
	 * Send broadcast RPC
	 * You can override it
	 * @param  string   $method Method name
	 * @param  array    $args   Arguments
	 * @param  callable $cb     Callback
	 * @return boolean Success
	 */
	public function broadcastCall($method, $args = [], $cb = NULL) {
		return Daemon::$process->IPCManager->sendBroadcastCall(
			'\\' . get_class($this) . ($this->name !== '' ? ':' . $this->name : ''),
			$method,
			$args,
			$cb
		);
	}

	/**
	 * Send RPC, executed once in any worker
	 * You can override it
	 * @param  string $method Method name
	 * @param  array  $args   Arguments
	 * @param  mixed  $cb     Callback
	 * @return boolean Success
	 */
	public function singleCall($method, $args = [], $cb = NULL) {
		return Daemon::$process->IPCManager->sendSingleCall(
			'\\' . get_class($this) . ($this->name !== '' ? ':' . $this->name : ''),
			$method,
			$args,
			$cb
		);
	}

	/**
	 * Send RPC, executed once in certain worker
	 * You can override it
	 * @param  integer $workerId Worker Id
	 * @param  string  $method   Method name
	 * @param  array   $args     Arguments
	 * @param  mixed   $cb       Callback
	 * @return boolean Success
	 */
	public function directCall($workerId, $method, $args = [], $cb = NULL) {
		return Daemon::$process->IPCManager->sendDirectCall(
			$workerId,
			'\\' . get_class($this) . ($this->name !== '' ? ':' . $this->name : ''),
			$method,
			$args,
			$cb
		);
	}

	/**
	 * Log something
	 * @param  string $message Message
	 * @return void
	 */
	public function log($message) {
		Daemon::log(get_class($this) . ': ' . $message);
	}

	/**
	 * Handle the request
	 * @param  object $parent   Parent request
	 * @param  object $upstream Upstream application
	 * @return object Request
	 */
	public function handleRequest($parent, $upstream) {
		$req = $this->beginRequest($parent, $upstream);
		return $req ? : $parent;
	}

	/**
	 * Create Request instance
	 * @param  object $req      Generic
	 * @param  object $upstream Upstream application instance
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
	 * Handle the worker status
	 * @param  integer $ret Status code
	 * @return boolean Result
	 */
	public function handleStatus($ret) {
		if ($ret === self::EVENT_CONFIG_UPDATED) {
			$this->onConfigUpdated();
			return true;
		}
		elseif ($ret === self::EVENT_GRACEFUL_SHUTDOWN) {
			return $this->onShutdown(true);
		}
		elseif ($ret === self::EVENT_SHUTDOWN) {
			return $this->onShutdown();
		}
		return false;
	}

	/**
	 * Called when worker is going to update configuration
	 * @todo call it only when application section config is changed
	 * @return void
	 */
	public function onConfigUpdated() {
	}

	/**
	 * Called when application instance is going to shutdown
	 * @return boolean Ready to shutdown?
	 */
	protected function onShutdown($graceful = false) {
		return true;
	}
}
