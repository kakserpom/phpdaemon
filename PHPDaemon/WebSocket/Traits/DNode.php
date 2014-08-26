<?php
namespace PHPDaemon\WebSocket\Traits;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Exceptions\UndefinedMethodCalled;
use PHPDaemon\Exceptions\ProtocolError;

/**
 * DNode
 *
 * @package WebSocket
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
trait DNode {
	protected $callbacks = [];
	protected $persistentCallbacks = [];
	protected $persistentMode = false;
	protected $counter = 0;
	protected $remoteMethods = [];
	protected $localMethods = [];

	public function defineLocalMethods($arr) {
		foreach ($arr as $k => $v) {
			$this->localMethods[$k] = $v;
		}
		$this->persistentMode = true;
		$this->callRemote('methods', $arr);
		$this->persistentMode = false;
	}

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

	public static function ensureCallback(&$arg) {
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

	public function extractCallbacks($args, &$list, &$path) {
		foreach ($args as $k => &$v) {
			if (is_array($v)) {
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
				$list[$id] = array_merge($path, [$k]);
			}
		}
	}

	public function callRemote() {
		$args = func_get_args();
		if (!sizeof($args)) {
			return $this;
		}
		$method = array_shift($args);
		$this->callRemoteArray($method, $args);
		return $this;
	}


	public function callRemoteArray($method, $args) {
		if (isset($this->remoteMethods[$method])) {
			call_user_func_array($this->remoteMethods[$method], $args);
			return $this;
		}
		$pct = [
			'method' => $method,
		];
		if (sizeof($args)) {
			$pct['arguments'] = $args;
			$callbacks = [];
			$path = [];
			$this->extractCallbacks($args, $callbacks, $path);
			if (sizeof($callbacks)) {
				$pct['callbacks'] = $callbacks;
			}
		}
		$this->sendPacket($pct);
		return $this;
	}

	public function methodsMethod($methods) {
		$this->remoteMethods = $methods;
	}

	public function toJson($p) {
		return json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public function toJsonDebugResursive(&$a) {
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
					$this->toJsonDebugResursive($v);
				}
			}
		}
	}
	public function toJsonDebug($p) {
		$this->toJsonDebugResursive($p);
		return $this->toJson($p);
	}

	public function sendPacket($p) {
		if (!$this->client) {
			return;
		}
		if (is_string($p['method']) && ctype_digit($p['method'])) {
			$p['method'] = (int) $p['method'];
		}
		$this->client->sendFrame($this->toJson($p) . "\n");
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

	protected static function setPath(&$m, $path, $val) {
		foreach ($path as $p) {
			$m =& $m[$p];
		}
		$m = $val;
	}

	protected static function &getPath(&$m, $path) {
		foreach ($path as $p) {
			$m =& $m[$p];
		}
		return $m;
	}

	/**
	 * @param array $args
	 * @return null|mixed
	 */
	public function __call($m, $args) {
		if (strncmp($m, 'remote_', 7) === 0) {
			$this->callRemoteArray(substr($m, 7), $args);
		} else {
			throw new UndefinedMethodCalled('Call to undefined method ' . get_class($this) . '->' . $m);
		}
	}
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
	 * Called when new frame received.
	 * @param string  Frame's contents.
	 * @param integer Frame's type.
	 * @param string $data
	 * @param string $type
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
