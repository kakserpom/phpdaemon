<?php
namespace PHPDaemon\Network;

use PHPDaemon\BoundSocket;
use PHPDaemon\Config;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Structures\ObjectStorage;
use PHPDaemon\Thread;

/**
 * Pool of connections
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
abstract class ConnectionPool extends ObjectStorage {

	/**
	 * Default connection class
	 * @var string
	 */
	public $connectionClass;

	/**
	 * Name
	 * @var string
	 */
	public $name;

	/**
	 * Configuration
	 * @var \PHPDaemon\Config\Section
	 */
	public $config;

	/**
	 * Instances storage
	 * @var array ['name' => ConnectionPool, ...]
	 */
	protected static $instances = [];

	/**
	 * Max concurrency
	 * @var integer
	 */
	public $maxConcurrency = 0;

	/**
	 * Is finished?
	 * @var boolean
	 */
	protected $finished = false;

	/**
	 * Is enabled?
	 * @var boolean
	 */
	protected $enabled = false;

	/**
	 * Is overloaded?
	 * @var boolean
	 */
	protected $overload = false;

	/**
	 * Constructor
	 * @param array Config variables
	 * @return object
	 */
	public function __construct($config = [], $init = true) {
		$this->config = $config;
		$this->onConfigUpdated();
		if ($this->connectionClass === null) {
			$e = explode('\\', get_class($this));
			$e[sizeof($e) - 1] = 'Connection';
			$this->connectionClass = '\\'. implode('\\', $e);
		}
	}

	/**
	 * Constructor
	 * @return void
	 */
	protected function init() { }

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		$this->enable();
	}

	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		if (Daemon::$process instanceof Thread\Worker) {
			if ($this->config === null) {
				$this->disable();
			}
			else {
				$this->enable();
			}
		}
		if ($defaults = $this->getConfigDefaults()) {
			$this->config->imposeDefault($defaults);
		}
		$this->applyConfig();
	}

	/**
	 * Applies config
	 * @return void
	 */
	protected function applyConfig() {
		foreach ($this->config as $k => $v) {
			if (is_object($v) && $v instanceof Config\Entry\Generic) {
				$v = $v->value;
			}
			$k = strtolower($k);
			if ($k === 'connectionclass') {
				$this->connectionClass = $v;
			}
			elseif ($k === 'name') {
				$this->name = $v;
			}
			elseif ($k === 'maxallowedpacket') {
				$this->maxAllowedPacket = (int)$v;
			}
		}
	}

	/**
	 * Setting default config options
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return false;
	}

	/**
	 * Returns instance object
	 * @param mixed String name / array config / ConfigSection
	 * @param [boolean Spawn? Default is true]
	 * @return object
	 */
	public static function getInstance($arg = '', $spawn = true) {
		if ($arg === 'default') {
			$arg = '';
		}
		$class = get_called_class();
		if (is_string($arg)) {
			$key = $class . ':' . $arg;
			if (isset(self::$instances[$key])) {
				return self::$instances[$key];
			}
			elseif (!$spawn) {
				return false;
			}
			$k = 'Pool:' . $class . ($arg !== '' ? ':' . $arg : '');

			$config    = (isset(Daemon::$config->{$k}) && Daemon::$config->{$k} instanceof Config\Section) ? Daemon::$config->{$k} : new Config\Section;
			$obj       = self::$instances[$key] = new $class($config);
			$obj->name = $arg;
			return $obj;
		}
		elseif ($arg instanceof Config\Section) {
			return new static($arg);

		}
		else {
			return new static(new Config\Section($arg));
		}
	}

	/**
	 * Sets default connection class
	 * @param string String name
	 * @return void
	 */
	public function setConnectionClass($class) {
		$this->connectionClass = $class;
	}

	/**
	 * Enable socket events
	 * @return void
	 */
	public function enable() {
		if ($this->enabled) {
			return;
		}
		$this->enabled = true;
		$this->onEnable();
	}

	/**
	 * Disable all events of sockets
	 * @return void
	 */
	public function disable() {
		if (!$this->enabled) {
			return;
		}
		$this->enabled = false;
		$this->onDisable();
	}

	/**
	 * Called when ConnectionPool is now enabled
	 * @return void
	 */
	protected function onEnable() {}

	/**
	 * Called when ConnectionPool is now disabled
	 * @return void
	 */
	protected function onDisable() {}

	/**
	 * Called when application instance is going to shutdown
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown($graceful = false) {
		return $this->finish();
	}

	/**
	 * Called when ConnectionPool is finished]
	 * @return void
	 */
	protected function onFinish() { }

	/**
	 * Finishes ConnectionPool
	 * @return boolean Success
	 */

	public function finish() {
		$this->disable();

		$result = true;

		foreach ($this as $conn) {
			if (!$conn->gracefulShutdown()) {
				$result = false;
			}
		}
		if ($result && !$this->finished) {
			$this->finished = true;
			$this->onFinish();
		}
		return $result;
	}

	/**
	 * Attach Connection
	 * @param Connection
	 * @param [mixed Info]
	 * @return void
	 */
	public function attach($conn, $inf = null) {
		parent::attach($conn, $inf);
		if ($this->maxConcurrency && !$this->overload) {
			if ($this->count() >= $this->maxConcurrency) {
				$this->overload = true;
				$this->disable();
				return;
			}
		}
	}

	/**
	 * Detach Connection
	 * @param Connection
	 * @param [mixed Info]
	 * @return void
	 */
	public function detach($conn) {
		parent::detach($conn);
		if ($this->overload) {
			if (!$this->maxConcurrency || ($this->count() < $this->maxConcurrency)) {
				$this->overload = false;
				$this->enable();
			}
		}
	}

	/**
	 * Establish a connection with remote peer
	 * @param string   URL
	 * @param callback Optional. Callback.
	 * @param string   Optional. Connection class name.
	 * @return integer Connection's ID. Boolean false when failed.
	 */
	public function connect($url, $cb, $class = null) {
		if ($class === null) {
			$class = $this->connectionClass;
		}
		$conn = new $class(null, $this);
		$conn->connect($url, $cb);
		return $conn;
	}
}
