<?php

/**
 * Pool of connections
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ConnectionPool extends ObjectStorage {

	public $allowedClients  = null;
	public $connectionClass;
	public $name;
	public $config;
	public static $instances = array();
	public $maxConcurrency = 0;
	public $finished = false;
	public $bound;
	public $enabled = false;
	
	public function __construct($config = array()) {
		$this->bound = new ObjectStorage;
		$this->config = $config;
		$this->onConfigUpdated();
		if ($this->connectionClass === null) {
			$this->connectionClass = get_class($this) . 'Connection';
		}
		if (isset($this->config->listen)) {
			$this->bind($this->config->listen->value);
		}
		$this->init();
	}
	
	public function init() {}
	
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
		if ($defaults = $this->getConfigDefaults()) {
			$this->processDefaultConfig($defaults);
		}
		if ($this->config === null) {
			return;
		}
		$this->applyConfig();
	}
	
	public function applyConfig() {
		foreach ($this->config as $k => $v) {
			if (is_object($v) && $v instanceof Daemon_ConfigEntry) {
				$v = $v->value;
			}
			$k = strtolower($k);
			if ($k === 'connectionclass') {
				$this->connectionClass = $v;
			}
			elseif ($k === 'name') {
				$this->name = $v;
			}
			elseif ($k === 'allowedclients') {
				$this->allowedClients = $v;
			}
			elseif ($k === 'maxallowedpacket') {
				$this->maxAllowedPacket = (int) $v;
			}
			elseif ($k === 'maxconcurrency') {
				$this->maxConcurrency = (int) $v;
			}
		}
	}
	/**
	 * Setting default config options
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return false;
	}
	
	public static function getInstance($arg = '') {
		if ($arg === 'default') {
			$arg = '';
		}
		$class = get_called_class();
		if (is_string($arg)) {
			$key = $class . ':' . $arg;
			if (isset(self::$instances[$key])) {
				return self::$instances[$key];
			}
			$k = 'Pool:' . $class . ($arg !== '' ? ':' . $arg : '' );
			
			$config = (isset(Daemon::$config->{$k}) && Daemon::$config->{$k} instanceof Daemon_ConfigSection) ? Daemon::$config->{$k}: new Daemon_ConfigSection;			
			$obj = self::$instances[$key] = new $class($config);
			$obj->name = $arg;
			return $obj;
		} elseif ($arg instanceof Daemon_ConfigSection) {
			return new $class($arg);

		} else {
			return new $class(new Daemon_ConfigSection($arg));
		}
	}
	
	
 	/**
	 * Process default config
	 * @todo move it to Daemon_Config class
	 * @param array {"setting": "value"}
	 * @return void
	 */
	public function processDefaultConfig($settings = array()) {
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
	
	public function setConnectionClass($class) {
		$this->connectionClass = $class;
	}
	
	/**
	 * Enable socket events
	 * @return void
	*/
	public function enable() {
		$this->enabled = true;
		$this->bound->each('enable');
	}
	
	/**
	 * Disable all events of sockets
	 * @return void
	 */
	public function disable() {
		$this->enabled = false;
		$this->bound->each('disable');
	}

	/**
	 * Called when application instance is going to shutdown
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown() {
		return $this->finish();
	}

	public function onFinish() {

	}


	/**
	 * Close each of binded sockets.
	 * @return void
	 */
	public function closeBound() {
		$this->bound->each('close');
	}


	public function finish() {
		$this->disable(); 
		$this->closeBound();
		
		$result = true;
	
		foreach ($this as $k => $conn) {
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

	public function attachBound($bound) {
		$this->bound->attach($bound);
	}

	public function detachBound($bound) {
		$this->bound->detach($bound);
	}

	public function attachConn($conn) {
		$this->attach($conn);
	}

	public function detachConn($conn) {
		$this->detach($conn);
		foreach ($this->bound as $bound) {
			if ($bound->overload) {
				$bound->onAcceptEvent();
			}
		}
	}
	
	/**
	 * Bind given sockets
	 * @param mixed Addresses to bind
	 * @param boolean SO_REUSE. Default is true
	 * @return void
	 */
	public function bind($addrs = array(), $reuse = true, $max = 0) {
		if (is_string($addrs)) {
			$addrs = explode(',', $addrs);
		}
		$n = 0;
		for ($i = 0, $s = sizeof($addrs); $i < $s; ++$i) {
			$addr = trim($addrs[$i]);
			if (stripos($addr, 'unix:') === 0) {
				$addr = substr($addr, 5);
				$socket = new BoundUNIXSocket($addr, $reuse);
				
			} else {
				if (stripos($addr,'tcp://') === 0) {
					$addr = substr($addr, 6);
				}
				$socket = new BoundTCPSocket($addr, $reuse);
				if (isset($this->config->port->value)) {
					$socket->setDefaultPort($this->config->port->value);
				}
			}
			if ($socket->bind()) {
				$socket->attachTo($this);
				if ($this->enabled) {
					$socket->enable();
				}
				++$n;
			}
			if ($max > 0 && ($n >= $max)) {
				return $n;
			}
		}
		return $n;
	}

	/**
	 * Establish a connection with remote peer
	 * @param string URL
	 * @param callback Optional. Callback.
	 * @param string Optional. Connection class name.
	 * @return integer Connection's ID. Boolean false when failed.
	 */
	public function connect($url, $cb, $class = null) {
		if ($class === null) {
			$class = $this->connectionClass;
		}
		$id = ++Daemon::$process->connCounter;
		$conn = new $class(null, $this);
		$conn->connect($url, $cb);
		return $conn;
	}


	/**
	 * Establish a connection with remote peer
	 * @param string Address
	 * @param string Optional. Default port
	 * @param callback Optional. Callback.
	 * @param string Optional. Connection class name.
	 * @return integer Connection's ID. Boolean false when failed.
	 */
	public function connectTo($addr, $port = 0, $cb = null, $class = null) {
		if ($class === null) {
			$class = $this->connectionClass;
		}
		$conn = new $class(null, $this);
		$conn->connectTo($addr, $port);
		if ($cb !== null) {
			$conn->onConnected($cb);
		}
		return $conn;
	}
}
