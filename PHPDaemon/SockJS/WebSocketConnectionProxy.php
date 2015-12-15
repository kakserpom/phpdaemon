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
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class WebSocketConnectionProxy implements \PHPDaemon\WebSocket\RouteInterface {
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	protected $realConn;

	protected $sockjs;

	/**
	 * __construct
	 * @param Application $sockjs
	 * @param object      $conn
	 */
	public function __construct($sockjs, $conn) {
		$this->sockjs = $sockjs;
		$this->realConn = $conn;
	}

	/**
	 * __get
	 * @param  string $k
	 * @return mixed
	 */
	public function &__get($k) {
		if (!isset($this->realConn->{$k})) {
			return null;
		}
		return $this->realConn->{$k};
	}
	
	/**
	 * __set
	 * * @param string $k Key
	 * * @param mixed $$v Value
	 * @return mixed
	 */
	public function __set($k, $v) {
		return $this->realConn->{$k} = $v;
	}

	/**
	 * __isset
	 * @param  string  $k 
	 * @return boolean
	 */
	public function __isset($k) {
		return isset($this->realConn->{$k});
	}
	
	
	/**
	 * Called when new frame received.
	 * @param  string $data Frame's data.
	 * @param  string $type Frame's type ("STRING" OR "BINARY").
	 * @return boolean      Success.
	 */
	public function onFrame($data, $type) {
		$this->realConn->onFrame($data, $type);
	}

	/**
	 * __call
	 * @param  string $method
	 * @param  array  $args
	 * @return mixed
	 */
	public function __call($method, $args) {
		return call_user_func_array([$this->realConn, $method], $args);
	}

	/**
	 * toJson
	 * @param  string $p
	 * @return string
	 */
	public function toJson($p) {
		return json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
	
	/**
	 * Sends a frame.
	 * @param string   $data Frame's data.
	 * @param integer  $type Frame's type. See the constants.
	 * @param callback $cb   Optional. Callback called when the frame is received by client.
	 * @callback $cb ( )
	 * @return boolean Success.
	 */
	public function sendFrame($data, $type = null, $cb = null) {
		$this->realConn->sendFrame('a' . $this->toJson([$data]), $type, $cb);
		return true;
	}

	/**
	 * Sends a frame.
	 * @param  string   $data Frame's data.
	 * @param  integer  $type Frame's type. See the constants.
	 * @param  callback $cb   Optional. Callback called when the frame is received by client.
	 * @callback $cb ( )
	 * @return boolean Success.
	 */
	public function sendFrameReal($data, $type = null, $cb = null) {
		$this->realConn->sendFrame($data, $type, $cb);
		return true;
	}
}
