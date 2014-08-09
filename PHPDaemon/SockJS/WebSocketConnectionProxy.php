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

	public function __call($method, $args) {
		D(['conn', $method]);
		return call_user_func_array([$this->realConn, $method], $args);
	}
	
	/**
	 * Sends a frame.
	 * @param string   Frame's data.
	 * @param integer  Frame's type. See the constants.
	 * @param callback Optional. Callback called when the frame is received by client.
	 * @return boolean Success.
	 */
	public function sendFrame($data, $type = 0x00, $cb = null) {
		$this->realConn->sendFrame('a' . json_encode([$data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $cb);
		return true;
	}

	/**
	 * Sends a frame.
	 * @param string   Frame's data.
	 * @param integer  Frame's type. See the constants.
	 * @param callback Optional. Callback called when the frame is received by client.
	 * @return boolean Success.
	 */
	public function sendFrameReal($data, $type = 0x00, $cb = null) {
		$this->realConn->sendFrame($data, $type, $cb);
		return true;
	}

}