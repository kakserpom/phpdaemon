<?php
namespace PHPDaemon\WebSocket;

use PHPDaemon\Core\Daemon;

/**
 * WebSocket protocol abstract class
 */

class Protocol {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	public $description;
	public $conn;

	const STRING = NULL;
	const BINARY = NULL;

	/**
	 * @TODO DESCR
	 * @param $conn
	 */
	public function __construct($conn) {
		$this->conn = $conn;
	}

	/**
	 * @TODO DESCR
	 * @param $type
	 * @return int|mixed|null
	 */
	public function getFrameType($type) {
		if (is_int($type)) {
			return $type;
		}
		if ($type === null) {
			$type = 'STRING';
		}
		$frametype = @constant($a = get_class($this) . '::' . $type);
		if ($frametype === null) {
			Daemon::log(__METHOD__ . ' : Undefined frametype "' . $type . '"');
		}
		return $frametype;
	}

	/**
	 * @TODO DESCR
	 * @return bool
	 */
	public function onHandshake() {
		return true;
	}

	/**
	 * @TODO DESCR
	 * @param $data
	 * @param $type
	 */
	public function sendFrame($data, $type) {
		$this->conn->write($this->encodeFrame($data, $type));
	}

	/**
	 * Called when new data received
	 * @return void
	 */
	public function onRead() {
	}

	/**
	 * Returns handshaked data for reply
	 * @param string Received data (no use in this class)
	 * @return string Handshaked data
	 */
	public function getHandshakeReply($data) {
		return false;
	}

}