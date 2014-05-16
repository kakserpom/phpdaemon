<?php
namespace PHPDaemon\DNode;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

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
	protected $counter = 0;
	protected $remoteMethods = [];

	/**
	 * Called when the connection is handshaked.
	 * @return void
	 */
	public function onHandshake() {
		parent::onHandshake();
	}

	public function defineLocalMethods($arr) {
		$this->callRemote('methods', $arr);

	}

	public function extractCallbacks($args, &$list, &$path) {
		foreach ($args as $k => &$v) {
			if (is_array($v)) {
				$path[] = $k;
				$this->extractCallbacks($v, $list, $path);
				array_pop($path);
			} elseif (is_callable($v)) {
				$id = ++$this->counter;
				$this->callbacks[$id] = $v;
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
		$callbacks = [];
		$path = [];
		$this->extractCallbacks($args, $callbacks, $path);
		$this->sendPacket([
			'method' => $method,
			'arguments' => $args,
			'callbacks' => $callbacks,
		]);
	}


	public function callRemoteArray($method, $args) {
		$callbacks = [];
		$path = [];
		$this->extractCallbacks($args, $callbacks, $path);
		$links = [];
		$this->sendPacket([
			'method' => $method,
			'arguments' => $args,
			'callbacks' => $callbacks,
			'links' => $links,
		]);
	}

	public function methodsMethod($methods) {
		$this->remoteMethods = $methods;
	}

	public function sendPacket($p) {
		$this->client->sendFrame(json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", 'STRING');
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
	 * Called when new frame received.
	 * @param string  Frame's contents.
	 * @param integer Frame's type.
	 * @return void
	 */
	public function onFrame($data, $type) {
		foreach (explode("\n", $data) as $pct) {
			$pct = json_decode($pct, true);
			$m = isset($pct['method']) ? $pct['method'] : null;
			$args = isset($pct['arguments']) ? $pct['arguments'] : [];
			if (isset($pct['callbacks']) && is_array($pct['callbacks'])) {
				foreach ($pct['callbacks'] as $id => $path) {
					static::setPath($args, $path, function () use ($id) {$this->callRemoteArray($id, func_get_args());});
				}
			}
			if (isset($pct['links']) && is_array($pct['links'])) {
				foreach ($pct['links'] as $link) {
					static::setPath($args, $link['to'], static::getPath($args, $link['from']));
					unset($r);
				}
			}
			if (is_string($m)) {
				if (is_callable($c = [$this, $m . 'Method'])) {
					call_user_func_array($c, $args);
				} else {
					$this->handleException(new UndefinedMethodException);
					continue;
				}
			}
			elseif (is_int($m)) {
				if (!isset($this->callbacks[$pct['method']])) {
					$this->handleException(new UndefinedMethodException);
					continue;
				}
			} else {
				$this->handleException(new ProtoException);
				continue;
			}
		}
	}
}
