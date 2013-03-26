<?php

/**
 * Websocket protocol abstract class
 */

class WebSocketProtocol {
	
	public $description;
	public $conn;

	const STRING = NULL;
	const BINARY = NULL;
	
	public function __construct($conn) {
		$this->conn = $conn;
	}

	public function getFrameType($type) {
		if (is_int($type)) {
			return $type;
		}
		if ($type === null) {
			$type = 'STRING';
		}
	    $frametype = @constant($a = get_class($this) .'::' . $type) ;
	    if ($frametype === null) {
	        Daemon::log(__METHOD__ . ' : Undefined frametype "' . $type . '"') ;
	    }
	    return $frametype ; 
	}
	
	public function onHandshake() {
		return true;
	}

	public function sendFrame($data, $type) {
		$this->conn->write($this->encodeFrame($data, $type)) ;
	}


	/**
     * Called when new data received
     * @return void
     */
	public function onRead() {}
	
    /**
     * Returns handshaked data for reply
	 * @param string Received data (no use in this class)
     * @return string Handshaked data
     */
    public function getHandshakeReply($data) {
		return false;
    }

}