<?php
namespace PHPDaemon\SockJS;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\Structures\StackCallbacks;
use PHPDaemon\Utils\Crypt;
/**
 * @package    Libraries
 * @subpackage SockJS
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */

class WebSocketRouteProxy implements \PHPDaemon\WebSocket\RouteInterface {
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	protected $heartbeatTimer;

	protected $realRoute;

	protected $sockjs;

	public function __construct($sockjs, $route) {
		$this->sockjs = $sockjs;
		$this->realRoute = $route;
	}
	public function __get($k) {
		return $this->realRoute->{$k};
	}

	public function __call($method, $args) {
		return call_user_func_array([$this->realRoute, $method], $args);
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
					$this->onPacket(rtrim($i, "\n"), $type);
				}
			} else {
				$this->onPacket($pct, $type);
			}
		}
	}

	public function onPacket($frame, $type) {
		$this->realRoute->onFrame($frame, $type);
	}

	/**
	 * @TODO DESCR
	 */
	public function onHandshake() {
		$this->realRoute->client->sendFrameReal('o');
		if (($f = $this->sockjs->config->heartbeatinterval->value) > 0) {
			$this->heartbeatTimer = setTimeout(function($timer) {
				$this->realRoute->client->sendFrameReal('h');
				$timer->timeout();
			}, $f * 1e6);
			$this->realRoute->onHandshake();
		}
	}

	/**
	 * @TODO DESCR
	 */
	public function onWrite() {
		if (method_exists($this->realRoute, 'onWrite')) {
			$this->realRoute->onWrite();
		}
	}
	
	/**
	 * @TODO DESCR
	 */
	public function onFinish() {
		Timer::remove($this->heartbeatTimer);
		if ($this->realRoute) {
			$this->realRoute->onFinish();
			$this->realRoute = null;
		}
	}

}