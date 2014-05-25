<?php
namespace PHPDaemon\DNode;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\Exceptions\UndefinedMethodCalled;

/**
 * Generic
 *
 * @package DNode
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
abstract class Generic extends \PHPDaemon\WebSocket\Route {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	protected $callbacks = [];
	protected $persistentCallbacks = [];
	protected $persistentMode = false;
	protected $counter = 0;
	protected $remoteMethods = [];
	protected $localMethods = [];
	protected $ioMode = false;
	protected $timer;

	/**
	 * Called when the connection is handshaked.
	 * @return void
	 */
	public function onHandshake() {
		if ($this->ioMode) {
			$this->client->sendFrame('o');
			$this->timer = setTimeout(function($event) {
				$this->client->sendFrame('h');
				$event->timeout();
			}, 15e6);
		}
		parent::onHandshake();
	}

	public function defineLocalMethods($arr) {
		foreach ($arr as $k => $v) {
			$this->localMethods[$k] = $v;
		}
		$this->persistentMode = true;
		$this->callRemote('methods', $arr);
		$this->persistentMode = false;
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
	}


	public function callRemoteArray($method, $args) {
		if (isset($this->remoteMethods[$method])) {
			call_user_func_array($this->remoteMethods[$method], $args);
			return;
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
		if (is_string($p['method']) && ctype_digit($p['method'])) {
			$p['method'] = (int) $p['method'];
		}
		if ($this->ioMode) {
			$this->client->sendFrame('a' . $this->toJson([$this->toJson($p) . "\n"], 'STRING'));
		} else {
			$this->client->sendFrame($this->toJson($p) . "\n", 'STRING');
		}
		if ($this->timer) {
			Timer::setTimeout($this->timer);
		}
	}

	
	public function fakeIncomingCallExtractCallbacks($args, &$list, &$path) {
		foreach ($args as $k => &$v) {
			if (is_array($v)) {
				$path[] = $k;
				$this->fakeIncomingCallExtractCallbacks($v, $list, $path);
				array_pop($path);
			} elseif ($v instanceof \Closure) {
				$id = ++$this->counter;
				$this->callbacks[$id] = $v;
				$list[$id] = array_merge($path, [$k]);
			}
		}
	}

	public function fakeIncomingCall() {
		$args = func_get_args();
		if (!sizeof($args)) {
			return $this;
		}
		$method = array_shift($args);
		$p = [
			'method' => $method,
		];
		if (sizeof($args)) {
			$path = [];
			$this->fakeIncomingCallExtractCallbacks($args, $callbacks, $path);
			$p['arguments'] = $args;
			$p['callbacks'] = $callbacks;
		}
		if ($this->ioMode) {
			$this->onFrame($this->toJson([$this->toJson($p) . "\n"], 'STRING'));
		} else {
			$this->onFrame($this->toJson($p)."\n", 'STRING');
		}
	}

	/**
	 * Called when session finished.
	 * @return void
	 */
	public function onFinish() {
		parent::onFinish();
		if ($this->timer) {
			Timer::remove($this->timer);
			$this->timer = null;
		}
		$this->remoteMethods = [];
		$this->localMethods = [];
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
	 * @param string $method
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
				if ($name = array_search($this->persistentCallbacks[$m], $this->localMethods, true)) {
					//D($args);
					Daemon::log('===>'.$name.'('.$this->toJsonDebug($args).')');
				}
				call_user_func_array($this->persistentCallbacks[$m], $args);
			}
			else {
				$this->handleException(new UndefinedMethodCalled);
			}
		} else {
			$this->handleException(new ProtoException);
		}
	}
	/**
	 * Called when new frame received.
	 * @param string  Frame's contents.
	 * @param integer Frame's type.
	 * @return void
	 */
	public function onFrame($data, $type) {
		foreach (explode("\n", $data) as $pct) {
			if ($pct === '') {
				continue;
			}
			$pct = json_decode($pct, true);
			if (isset($pct[0])) {
				foreach ($pct as $i) {
					$this->onPacket(json_decode(rtrim($i), true));
				}
			} else {
				$this->onPacket($pct);
			}
		}
	}
}
