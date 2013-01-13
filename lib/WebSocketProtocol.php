<?php

/**
 * Websocket protocol abstract class
 */

class WebSocketProtocol {
	
	public $description;
	public $connection;

	const STRING = NULL;
	const BINARY = NULL;
	
	public function __construct($connection) {
		$this->connection = $connection;
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
		$this->connection->write($this->encodeFrame($data, $type)) ;
	}

	public function onRead() {
		$this->connection->buf = "" ;
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