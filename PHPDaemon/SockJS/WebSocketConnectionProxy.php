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

class WebSocketConnectionProxy implements \PHPDaemon\WebSocket\RouteInterface {
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	protected $realConn;
	protected $sockjs;

	public function __construct($sockjs, $conn) {
		$this->sockjs = $sockjs;
		$this->realConn = $conn;
	}
	public function __get($k) {
		return $this->realConn->{$k};
	}

	public function __isset($k) {
		return isset($this->realConn->{$k});
	}

	public function __call($method, $args) {
		return call_user_func_array([$this->realConn, $method], $args);
	}

	public function toJson($p) {
		return json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}
	
	/**
	 * Sends a frame.
	 * @param string   Frame's data.
	 * @param integer  Frame's type. See the constants.
	 * @param callback Optional. Callback called when the frame is received by client.
	 * @return boolean Success.
	 */
	public function sendFrame($data, $type = null, $cb = null) {
		$this->realConn->sendFrame('a' . $this->toJson([$data]), $type, $cb);
		return true;
	}

	/**
	 * Sends a frame.
	 * @param string   Frame's data.
	 * @param integer  Frame's type. See the constants.
	 * @param callback Optional. Callback called when the frame is received by client.
	 * @return boolean Success.
	 */
	public function sendFrameReal($data, $type = null, $cb = null) {
		$this->realConn->sendFrame($data, $type, $cb);
		return true;
	}

}