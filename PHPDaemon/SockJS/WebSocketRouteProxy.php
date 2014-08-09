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
	
	protected $finishTimer;

	protected $pingTimer;

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
		D(['route', $method] , Debug::backtrace());
		return call_user_func_array([$this->realRoute, $method], $args);
	}

	public function handleException($e) {
		Daemon::log((string) $e);
		return$this->realRoute->handleException($e);
	}

	public function onFrame($msg, $type) {
		$frames = json_decode($msg, true);
		if (!is_array($frames)) {
			return;
		}
		foreach ($frames as $frame) {
			$this->realRoute->onFrame($frame, $type);
		}
	}

	/**
	 * @TODO DESCR
	 */
	public function onHandshake() {
		$this->realRoute->client->sendFrameReal('o');
		$this->pingTimer = setTimeout(function($timer) {
			$this->realRoute->client->sendFrameReal('h');
			$timer->timeout();
		}, 15e6);
		$this->realRoute->onHandshake();
	}

	/**
	 * @TODO DESCR
	 */
	public function onWrite() {
		if (method_exists($this->realRoute, 'onWrite')) {
			$this->realRoute->onWrite();
		}
	}
	/*public function __destruct() {
		D('destructed session '.$this->id);
	}*/

	/**
	 * @TODO DESCR
	 */
	public function onFinish() {
		Timer::remove($this->finishTimer);
		Timer::remove($this->pingTimer);
		if ($this->realRoute) {
			$this->realRoute->onFinish();
			$this->realRoute = null;
		}
	}

}