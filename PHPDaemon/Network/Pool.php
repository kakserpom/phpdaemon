<?php
namespace PHPDaemon\Network;

use PHPDaemon\BoundSocket;
use PHPDaemon\Config;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Structures\ObjectStorage;
use PHPDaemon\Thread;

/**
 * Pool of connections
 * @package PHPDaemon\Network
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
abstract class Pool extends ObjectStorage {

	/**
	 * @var string Default connection class
	 */
	public $connectionClass;

	/**
	 * @var string Name
	 */
	public $name;

	/**
	 * @var \PHPDaemon\Config\Section Configuration
	 */
	public $config;

	/**
	 * @var ConnectionPool[] Instances storage
	 */
	protected static $instances = [];

	/**
	 * @var integer Max concurrency
	 */
	public $maxConcurrency = 0;

	/**
	 * @var integer Max allowed packet
	 */
	public $maxAllowedPacket = 0;

	/**
	 * @var boolean Is finished?
	 */
	protected $finished = false;

	/**
	 * @var boolean Is enabled?
	 */
	protected $enabled = false;

	/**
	 * @var boolean Is overloaded?
	 */
	protected $overload = false;

	/**
	 * @var object|null Application instance object
	 */
	public $appInstance;

	/**
	 * Constructor
	 * @param array   $config Config variables
	 * @param boolean $init
	 */
	public function __construct($config = [], $init = true) {
		$this->config = $config;
		$this->onConfigUpdated();
		if ($this->connectionClass === null) {
			$e                     = explode('\\', get_class($this));
			$e[sizeof($e) - 1]     = 'Connection';
			$this->connectionClass = '\\' . implode('\\', $e);
		}
		if ($init) {
			$this->init();
		}
	}

	/**
	 * Init
	 * @return void
	 */
	protected function init() {
	}

	/**
	 * Called when the worker is ready to go
	 * @return void
	 */
	public function onReady() {
		$this->enable();
	}

	/**
	 * Called when worker is going to update configuration
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
				$v = $v->getValue();
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
			elseif ($k === 'maxconcurrency') {
				$this->maxConcurrency = (int)$v;
			}
		}
	}

	/**
	 * Setting default config options
	 * @return boolean
	 */
	protected function getConfigDefaults() {
		return false;
	}

	/**
	 * Returns instance object
	 * @param  string  $arg   name / array config / ConfigSection
	 * @param  boolean $spawn Spawn? Default is true
	 * @return this
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
			$k         = '\PHPDaemon\Core\Pool:\\' . $class . ($arg !== '' ? ':' . $arg : '');
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
	 * @param  string $class Connection class name
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
	protected function onEnable() {
	}

	/**
	 * Called when ConnectionPool is now disabled
	 * @return void
	 */
	protected function onDisable() {
	}

	/**
	 * Called when application instance is going to shutdown
	 * @param  boolean $graceful
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown($graceful = false) {
		return $this->finish($graceful);
	}

	/**
	 * Called when ConnectionPool is finished
	 * @return void
	 */
	protected function onFinish() {
	}

	/**
	 * Finishes ConnectionPool
	 * @return boolean Success
	 */
	public function finish($graceful = false) {
		$this->disable();
		
		if (!$this->finished) {
			$this->finished = true;
			$this->onFinish();
		}
		
		$result = true;

		foreach ($this as $conn) {
			if ($graceful) {
				if (!$conn->gracefulShutdown()) {
					$result = false;
				}
			} else {
				$conn->finish();
			}
		}

		return $result;
	}

	/**
	 * Attach Connection
	 * @param  object $conn Connection
	 * @param  mixed  $inf  Info
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
	 * @param  object $conn Connection
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
	 * @param  string   $url   URL
	 * @param  callback $cb    Callback
	 * @param  string   $class Optional. Connection class name
	 * @return integer         Connection's ID. Boolean false when failed
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
