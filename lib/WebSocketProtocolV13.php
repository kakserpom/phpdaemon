<?php
/**
 * Websocket protocol 13
 * @see	http://datatracker.ietf.org/doc/rfc6455/?include_text=1
 */

class WebSocketProtocolV13 extends WebSocketProtocol {
	const CONTINUATION = 0;
	const STRING = 0x1;
	const BINARY = 0x2;
	const CONNCLOSE = 0x8;
	const PING = 0x9;
	const PONG = 0xA ;
	protected static $opcodes = [
		0 => 'CONTINUATION',
		0x1 => 'STRING',
		0x2 => 'BINARY',
		0x3 => 'CONNCLOSE',
		0x9 => 'PING',
		0xA => 'PONG',													
	];
	protected $outgoingCompression = 0;

	public function onHandshake(){
        if (!isset($this->conn->server['HTTP_SEC_WEBSOCKET_KEY']) || !isset($this->conn->server['HTTP_SEC_WEBSOCKET_VERSION'])) {
			return false;
		}
		if ($this->conn->server['HTTP_SEC_WEBSOCKET_VERSION'] !== '13' && $this->conn->server['HTTP_SEC_WEBSOCKET_VERSION'] !== '8') {
            return false;
        }

		return true;
	}

    /**
     * Returns handshaked data for reply
	 * @param string Received data (no use in this class)
     * @return string Handshaked data
     */

    public function getHandshakeReply($data) {
        if ($this->onHandshake()) {
	    	if (isset($this->conn->server['HTTP_ORIGIN'])) {
				$this->conn->server['HTTP_SEC_WEBSOCKET_ORIGIN'] = $this->conn->server['HTTP_ORIGIN'];
			}
            if (!isset($this->conn->server['HTTP_SEC_WEBSOCKET_ORIGIN']))
			{
                $this->conn->server['HTTP_SEC_WEBSOCKET_ORIGIN'] = '' ;
            }
            $reply = "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: WebSocket\r\n"
                . "connection: Upgrade\r\n"
                . "Date: ".date('r')."\r\n"
                . "Sec-WebSocket-Origin: " . $this->conn->server['HTTP_SEC_WEBSOCKET_ORIGIN'] . "\r\n"
                . "Sec-WebSocket-Location: ws://" . $this->conn->server['HTTP_HOST'] . $this->conn->server['REQUEST_URI'] . "\r\n"
                . "Sec-WebSocket-Accept: " . base64_encode(sha1(trim($this->conn->server['HTTP_SEC_WEBSOCKET_KEY']) . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true)) . "\r\n";

            if (isset($this->conn->server['HTTP_SEC_WEBSOCKET_PROTOCOL'])) {
                $reply .= "Sec-WebSocket-Protocol: " . $this->conn->server['HTTP_SEC_WEBSOCKET_PROTOCOL'] . "\r\n";
            }

			if ($this->conn->pool->config->expose->value) {
				$reply .= 'X-Powered-By: phpDaemon/' . Daemon::$version . "\r\n";
			}

            $reply .= "\r\n";
            return $reply ;
        }

		return false;
    }

	/**
	 * Data encoding, according to related IETF draft
	 * 
	 * @see http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-10#page-16
	 */

    protected function encodeFrame($data, $type = 'STRING') {
		$fin = 1;
		$rsv1 = 0;
		$rsv2 = 0;
		$rsv3 = 0;
		if (in_array($type, array('STRING', 'BINARY')) && ($this->outgoingCompression > 0) && in_array('deflate-frame', $this->conn->extensions)) {
			//$data = gzcompress($data, $this->outgoingCompression);
			//$rsv1 = 1;
		}
		return $this->encodeFragment($data, $type, $fin, $rsv1, $rsv2, $rsv3);
    }

	protected function encodeFragment($data, $type, $fin = 1, $rsv1 = 0, $rsv2 = 0, $rsv3 = 0) {
        $mask =	chr(rand(0, 0xFF)) .
 						chr(rand(0, 0xFF)) . 
						chr(rand(0, 0xFF)) . 
						chr(rand(0, 0xFF)) ;
		$packet = chr(bindec($fin . $rsv1 . $rsv2 . $rsv3 . str_pad(decbin($this->getFrameType($type)), 4, '0', STR_PAD_LEFT)));
        $dataLength = strlen($data);
		$isMasked = false;
		$isMaskedInt = $isMasked ? 128 : 0;
        if ($dataLength <= 125)
        {
            $packet .= chr($dataLength + $isMaskedInt);
        }
        elseif ($dataLength <= 65535)
        {
            $packet .=	chr(126 + $isMaskedInt) . // 126 + 128
            			chr($dataLength >> 8) .
            			chr($dataLength & 0xFF);
        }
        else {
            $packet .=	chr(127 + $isMaskedInt) . // 127 + 128
             			chr($dataLength >> 56) .
           				chr($dataLength >> 48) .
            			chr($dataLength >> 40) .
             			chr($dataLength >> 32) .
             			chr($dataLength >> 24) .
             			chr($dataLength >> 16) .
               			chr($dataLength >>  8) .
             			chr($dataLength & 0xFF);
        }
        if ($isMasked) { 
        	$packet .=	$mask . $this->mask($data, $mask);
		} else {
 			$packet .= $data;
		}
		return $packet;
	}
	public function mask ($data, $mask) {
   		for ($i = 0, $l = strlen($data), $ml = strlen($mask); $i < $l; $i++) {
	     	$data[$i] = $data[$i] ^ $mask[$i % $ml] ;
	     }
		return $data;
	}

	/**
	 * Data decoding, according to related IETF draft
	 * 
	 * @see http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-10#page-16
	 */

    public function onRead() {
		$data = '';
		while ($this->conn && (($buflen = $this->conn->getInputLength()) >= 2)) {
			$first = ord($this->conn->look(1)); // first byte integer (fin, opcode)
			$firstBits = decbin($first);
			$rsv1 = (bool) $firstBits[1];
			$rsv2 = (bool) $firstBits[2];
			$rsv3 = (bool) $firstBits[3];
			$opcode = (int) bindec(substr($firstBits, 4, 4));
			if ($opcode === 0x8) { // CLOSE
        		$this->conn->finish();
            	return;
			}
			$opcodeName = isset(static::$opcodes[$opcode]) ? static::$opcodes[$opcode] : false;
			if (!$opcodeName) {
				Daemon::log(get_class($this) . ': Undefined opcode '.$opcode);
				$this->conn->finish();
				return;
			}
			$second = ord($this->conn->look(1, 1)); // second byte integer (masked, payload length)
			$fin =	(bool) ($first >> 7);
        	$isMasked   = (bool) ($second >> 7);
        	$dataLength = $second & 0x7f;
        	$p = 2;
          	if ($dataLength === 0x7e) { // 2 bytes-length
				if ($buflen < $p + 2) {
					return; // not enough data yet
				}
				$dataLength = Binary::bytes2int($this->conn->look(2, $p), false);
				$p += 2;
			}
			elseif ($dataLength === 0x7f) { // 8 bytes-length
				if ($buflen < $p + 8) {
					return; // not enough data yet
				}
            	$dataLength = Binary::bytes2int($this->conn->look(8, $p));
            	$p += 8;
            }
			if ($this->conn->pool->maxAllowedPacket <= $dataLength) {
				// Too big packet
				$this->conn->finish();
				return;
			}
			if ($isMasked) {
				if ($buflen < $p + 4) {
					return; // not enough data yet
				}
				$mask = $this->conn->look(4, $p);
				$p += 4;
			}
			if ($buflen < $p + $dataLength) {
				return; // not enough data yet
			}
			$this->conn->drain($p);
			$data = $this->conn->read($dataLength);
			if ($isMasked) {
				$data = $this->mask($data, $mask);
			}
			//Daemon::log(Debug::dump(array('ext' => $this->conn->extensions, 'rsv1' => $rsv1, 'data' => Debug::exportBytes($data))));
			if ($rsv1 && in_array('deflate-frame', $this->conn->extensions)) { // deflate frame
				//$data = gzuncompress($data, $this->conn->pool->maxAllowedPacket);
			}
			if (!$fin) {
				$this->conn->framebuf .= $data;
			} else {
				$this->conn->onFrame($this->conn->framebuf . $data, $opcodeName);
				if ($this->conn) {
					$this->conn->framebuf = '';
				}
			}
		}
    }
}
