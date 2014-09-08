<?php
namespace PHPDaemon\WebSocket\Traits;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Exceptions\UndefinedMethodCalled;
use PHPDaemon\Exceptions\ProtocolError;

/**
 * DNode
 * @package PHPDaemon\WebSocket\Traits
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
trait DNode {
	/**
	 * @var array Associative array of callback functions registered by callRemote()
	 */
	protected $callbacks = [];

	/**
	 * @var array Associative array of persistent callback functions registered by callRemote()
	 */
	protected $persistentCallbacks = [];

	/**
	 * @var boolean If true, callRemote() will register callbacks as persistent ones
	 */
	protected $persistentMode = false;

	/**
	 * @var integer Incremental counter of callback functions registered by callRemote() 
	 */
	protected $counter = 0;

	/**
	 * @var array Associative array of registered remote methods (received in 'methods' call)
	 */
	protected $remoteMethods = [];

	/**
	 * @var array Associative array of local methods, set by defineLocalMethods()
	 */
	protected $localMethods = [];

	/**
	 * @var boolean Was this object cleaned up?
	 */
	protected $cleaned = false;

	/**
	 * Default onHandshake() method
	 * @return void
	 */
	public function onHandshake() {
		$this->defineLocalMethods();
	}

	/**
	 * Defines local methods
	 * @param  array $arr Associative array of callbacks (methodName => callback)
	 * @return void
	 */
	protected function defineLocalMethods($arr = []) {
		foreach (get_class_methods($this) as $m) {
			if (substr($m, -6) === 'Method') {
				$k = substr($m, 0, -6);
				if ($k === 'methods') {
					continue;
				}
				$arr[$k] = [$this, $m];
			}
		}
		foreach ($arr as $k => $v) {
			$this->localMethods[$k] = $v;
		}
		$this->persistentMode = true;
		$this->callRemote('methods', $arr);
		$this->persistentMode = false;
	}

	/**
	 * Calls a local method
	 * @param  string $method  Method name
	 * @param  mixed  ...$args Arguments
	 * @return this
	 */
	public function callLocal() {
		$args = func_get_args();
		if (!sizeof($args)) {
			return $this;
		}
		$method = array_shift($args);
		$p = [
			'method' => $method,
			'arguments' => $args,
		];
		$this->onPacket($p);
		return $this;
	}


	/**
	 * Ensures that the variable passed by reference holds a valid callback-function
	 * If it doesn't, its value will be reset to null
	 * @param  mixed   &$arg Argument
	 * @return boolean
	 */
	protected static function ensureCallback(&$arg) {
		if ($arg instanceof \Closure) {
			return true;
		}
		if (is_array($arg) && sizeof($arg) === 2) {
			if (isset($arg[0]) && $arg[0] instanceof \PHPDaemon\WebSocket\Route) {
				if (isset($arg[1]) && is_string($arg[1]) && strncmp($arg[1], 'remote_', 7) === 0) {
					return true;
				}
			}
		}
		$arg = null;
		return false;
	}

	/**
	 * Extracts callback functions from array of arguments
	 * @param  array &$args Arguments
	 * @param  array &$list Output array for 'callbacks' property
	 * @param  array &$path Recursion path holder
	 * @return void
	 */
	public function extractCallbacks(&$args, &$list, &$path) {
		foreach ($args as $k => &$v) {
			if (is_array($v)) {
				if (sizeof($v) === 2) {
					if (isset($v[0]) && is_object($v[0])) {
						if (isset($v[1]) && is_string($v[1])) {
							$id = ++$this->counter;
							if ($this->persistentMode) {
								$this->persistentCallbacks[$id] = $v;
							} else {
								$this->callbacks[$id] = $v;
							}
							$v = '';
							$list[$id] = $path;
							array_push($list[$id], $k);
							continue;
						}
					}
				}
				$path[] = $k;
				$this->extractCallbacks($v, $list, $path);
				array_pop($path);
			} elseif ($v instanceof \Closure) {
				$id = ++$this->counter;
				if ($this->persistentMode) {
					$this->persistentCallbacks[$id] = $v;
				} else {
					$this->callbacks[$id] = $v;
				}
				$v = '';
				$list[$id] = $path;
				array_push($list[$id], $k);
			}
		}
	}

	/**
	 * Calls a remote method
	 * @param  string $method  Method name
	 * @param  mixed  ...$args Arguments
	 * @return this
	 */
	public function callRemote() {
		$args = func_get_args();
		if (!sizeof($args)) {
			return $this;
		}
		$method = array_shift($args);
		$this->callRemoteArray($method, $args);
		return $this;
	}

	/**
	 * Calls a remote method with array of arguments
	 * @param  string $method Method name
	 * @param  array  $args   Arguments
	 * @return this
	 */
	public function callRemoteArray($method, $args) {
		if (isset($this->remoteMethods[$method])) {
			call_user_func_array($this->remoteMethods[$method], $args);
			return $this;
		}
		$pct = [
			'method' => $method,
		];
		if (sizeof($args)) {
			$callbacks = [];
			$path = [];
			$this->extractCallbacks($args, $callbacks, $path);
			$pct['arguments'] = $args;
			if (sizeof($callbacks)) {
				$pct['callbacks'] = $callbacks;
			}
		}
		$this->sendPacket($pct);
		return $this;
	}

	/**
	 * Handler of the 'methods' method
	 * @param  array $methods Associative array of methods
	 * @return void
	 */
	public function methodsMethod($methods) {
		$this->remoteMethods = $methods;
	}

	/**
	 * Encodes value into JSON
	 * @param  mixed $m Value
	 * @return this
	 */
	protected static function toJson($m) {
		return json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	/**
	 * Recursion handler for toJsonDebug()
	 * @param  array &$a Data
	 * @return void
	 */
	public static function toJsonDebugResursive(&$a) {
		foreach ($a as $k => &$v) {
			if ($v instanceof \Closure) {
				$v = '__CALLBACK__';
			}
			elseif (is_array($v)) {
				if (sizeof($v) === 2 && isset($v[0]) && $v[0] instanceof \PHPDaemon\WebSocket\Route) {
					if (isset($v[1]) && is_string($v[1]) && strncmp($v[1], 'remote_', 7) === 0) {
						$v = '__CALLBACK__';
					}
				} else {
					static::toJsonDebugResursive($v);
				}
			}
		}
	}

	/**
	 * Encodes object into JSON for debugging purposes
	 * @param  array &$p Data
	 * @return void
	 */
	public static function toJsonDebug($p) {
		static::toJsonDebugResursive($p);
		return static::toJson($p);
	}

	/**
	 * Sends a packet
	 * @param  array &$pct Data
	 * @return void
	 */
	protected function sendPacket($pct) {
		if (!$this->client) {
			return;
		}
		if (is_string($pct['method']) && ctype_digit($pct['method'])) {
			$pct['method'] = (int) $pct['method'];
		}
		$this->client->sendFrame(static::toJson($pct) . "\n");
	}

	/**
	 * Called when session is finished
	 * @return void
	 */
	public function onFinish() {
		$this->cleanup();
		parent::onFinish();
	}

	/**
	 * Swipes internal structures
	 * @return void
	 */
	public function cleanup() {
		$this->cleaned = true;
		$this->remoteMethods = [];
		$this->localMethods = [];
		$this->persistentCallbacks = [];
		$this->callbacks = [];
	}


	/**
	 * Sets value by materialized path
	 * @param  array &$m
	 * @param  array $path
	 * @param  mixed $val
	 * @return void
	 */
	protected static function setPath(&$m, $path, $val) {
		foreach ($path as $p) {
			$m =& $m[$p];
		}
		$m = $val;
	}

	/**
	 * Finds value by materialized path
	 * @param  array &$m
	 * @param  array $path
	 * @return mixed Value
	 */
	protected static function &getPath(&$m, $path) {
		foreach ($path as $p) {
			$m =& $m[$p];
		}
		return $m;
	}

	/**
	 * Magic __call method
	 * @param  string $method Method name
	 * @param  array  $args   Arguments
	 * @throws UndefinedMethodCalled if method name not start from 'remote_'
	 * @return mixed
	 */
	public function __call($method, $args) {
		if (strncmp($method, 'remote_', 7) === 0) {
			$this->callRemoteArray(substr($method, 7), $args);
		} else {
			throw new UndefinedMethodCalled('Call to undefined method ' . get_class($this) . '->' . $method);
		}
	}

	/**
	 * Called when new packet is received
	 * @param  array $pct Packet
	 * @return void
	 */
	public function onPacket($pct) {
		if ($this->cleaned) {
			return;
		}
		$m = isset($pct['method']) ? $pct['method'] : null;
		$args = isset($pct['arguments']) ? $pct['arguments'] : [];
		if (isset($pct['callbacks']) && is_array($pct['callbacks'])) {
			foreach ($pct['callbacks'] as $id => $path) {
				static::setPath($args, $path, [$this, 'remote_' . $id]);
			}
		}
		if (isset($pct['links']) && is_array($pct['links'])) {
			foreach ($pct['links'] as $link) {
				static::setPath($args, $link['to'], static::getPath($args, $link['from']));
				unset($r);
			}
		}

		if (is_string($m)) {
			if (isset($this->localMethods[$m])) {
				call_user_func_array($this->localMethods[$m], $args);
			}
			elseif (method_exists($this, $m . 'Method')) {
				call_user_func_array([$this, $m . 'Method'], $args);
			} else {
				$this->handleException(new UndefinedMethodCalled);
			}
		}
		elseif (is_int($m)) {
			if (isset($this->callbacks[$m])) {
				if (!call_user_func_array($this->callbacks[$m], $args)) {
					unset($this->callbacks[$m]);
				}
			}
			elseif (isset($this->persistentCallbacks[$m])) {
				call_user_func_array($this->persistentCallbacks[$m], $args);
			}
			else {
				$this->handleException(new UndefinedMethodCalled);
			}
		} else {
			$this->handleException(new ProtocolError);
		}
	}

	/**
	 * Called when new frame is received
	 * @param  string $data Frame's contents
	 * @param  string $type Frame's type
	 * @return void
	 */
	public function onFrame($data, $type) {
		foreach (explode("\n", $data) as $pct) {
			if ($pct === '') {
				continue;
			}
			$this->onPacket(json_decode($pct, true));
		}
	}
}
