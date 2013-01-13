<?php
/**
 * Websocket protocol 13
 * @see	http://datatracker.ietf.org/doc/rfc6455/?include_text=1
 */

class WebSocketProtocolV13 extends WebSocketProtocol
{
	// @todo manage only the 4 last bits (opcode), as described in the draft
	const CONTINUATION = 0;
	const STRING = 0x1;
	const BINARY = 0x2;
	const CONNCLOSE = 0x8;
	const PING = 0x9;
	const PONG = 0xA ;
	public $opcodes;
	public $outgoingCompression = 0;
		
	public function __construct($connection) {
		$this->connection = $connection;
		$this->opcodes = array(
			0 => 'CONTINUATION',
			0x1 => 'STRING',
			0x2 => 'BINARY',
			0x3 => 'CONNCLOSE',
			0x9 => 'PING',
			0xA => 'PONG',													
		);
	}

	public function onHandshake(){
        if (!isset($this->connection->server['HTTP_SEC_WEBSOCKET_KEY'])	|| !isset($this->connection->server['HTTP_SEC_WEBSOCKET_VERSION'])) {
			return false;
		}
		if ($this->connection->server['HTTP_SEC_WEBSOCKET_VERSION'] != '13' && $this->connection->server['HTTP_SEC_WEBSOCKET_VERSION'] != '8') {
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
	    	if (isset($this->connection->server['HTTP_ORIGIN'])) {
				$this->connection->server['HTTP_SEC_WEBSOCKET_ORIGIN'] = $this->connection->server['HTTP_ORIGIN'];
			}
            if (!isset($this->connection->server['HTTP_SEC_WEBSOCKET_ORIGIN']))
			{
                $this->connection->server['HTTP_SEC_WEBSOCKET_ORIGIN'] = '' ;
            }
            $reply = "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: WebSocket\r\n"
                . "connection: Upgrade\r\n"
                . "Date: ".date('r')."\r\n"
                . "Sec-WebSocket-Origin: " . $this->connection->server['HTTP_SEC_WEBSOCKET_ORIGIN'] . "\r\n"
                . "Sec-WebSocket-Location: ws://" . $this->connection->server['HTTP_HOST'] . $this->connection->server['REQUEST_URI'] . "\r\n"
                . "Sec-WebSocket-Accept: " . base64_encode(sha1(trim($this->connection->server['HTTP_SEC_WEBSOCKET_KEY']) . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true)) . "\r\n";

            if (isset($this->connection->server['HTTP_SEC_WEBSOCKET_PROTOCOL'])) {
                $reply .= "Sec-WebSocket-Protocol: " . $this->connection->server['HTTP_SEC_WEBSOCKET_PROTOCOL'] . "\r\n";
            }

			if ($this->connection->pool->config->expose->value) {
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
		if (in_array($type, array('STRING', 'BINARY')) && ($this->outgoingCompression > 0) && in_array('deflate-frame', $this->connection->extensions)) {
			$data = gzcompress($data, $this->outgoingCompression);
			$rsv1 = 1;
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
	 * Converts binary string to integer
	 * @param string Binary string
	 * @param boolean Optional. Little endian. Default value - false.
	 * @return integer Resulting integer
	 */
	public function bytes2int($str, $l = FALSE)
	{
		if ($l) {
			$str = strrev($str);
		}
		
		$dec = 0;
		$len = strlen($str);
		
		for($i = 0; $i < $len; ++$i) {
			$dec += ord(binarySubstr($str, $i, 1)) * pow(0x100, $len - $i - 1);
		}
		
		return $dec;
	}

	/**
	 * Converts integer to binary string
	 * @param integer Length
	 * @param integer Integer
	 * @param boolean Optional. Little endian. Default value - false.
	 * @return string Resulting binary string
	 */
	function int2bytes($len, $int = 0, $l = FALSE) {
		$hexstr = dechex($int);

		if ($len === NULL) {
			if (strlen($hexstr) % 2) {
				$hexstr = "0".$hexstr;
			}
		} else {
			$hexstr = str_repeat('0', $len * 2 - strlen($hexstr)) . $hexstr;
		}
		
		$bytes = strlen($hexstr) / 2;
		$bin = '';
		
		for($i = 0; $i < $bytes; ++$i) {
			$bin .= chr(hexdec(substr($hexstr, $i * 2, 2)));
		}
		
		return $l ? strrev($bin) : $bin;
	}
	/**
	 * Data decoding, according to related IETF draft
	 * 
	 * @see http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-10#page-16
	 */

    public function onRead() {
		$data = '';
		while ($this->connection && (($buflen = strlen($this->connection->buf)) >= 1)) {
			$p = 0; // offset
			$first = ord(binarySubstr($this->connection->buf, $p++, 1)); // first byte integer (fin, opcode)
			$firstBits = decbin($first);
			$rsv1 = (bool) $firstBits[1];
			$rsv2 = (bool) $firstBits[2];
			$rsv3 = (bool) $firstBits[3];
			$opcode = (int) bindec(substr($firstBits, 4, 4));
			if ($opcode === 0x8) { // CLOSE
        		$this->connection->finish();
            	return;
			}
			$opcodeName = $this->opcodes[$opcode];
			$second = ord(binarySubstr($this->connection->buf, $p++, 1)); // second byte integer (masked, payload length)
			$fin =	(bool) ($first >> 7);
        	$isMasked   = (bool) ($second >> 7);
        	$dataLength = $second & 0x7f;
          	if ($dataLength === 0x7e) { // 2 bytes-length
				if ($buflen < $p + 2) {
					return; // not enough data yet
				}
				$dataLength = $this->bytes2int(binarySubstr($this->connection->buf, $p, 2), false);
				$p += 2;
			}
			elseif ($dataLength === 0x7f) { // 4 bytes-length
				if ($buflen < $p + 4) {
					return; // not enough data yet
				}
            	$dataLength = $this->bytes2int(binarySubstr($this->connection->buf, $p, 4));
            	$p += 4;
            }
			if ($this->connection->pool->maxAllowedPacket <= $dataLength) {
				// Too big packet
				$this->connection->finish();
				return;
			}

			if ($isMasked) {
				if ($buflen < $p + 4) {
					return; // not enough data yet
				}
				$mask = binarySubstr($this->connection->buf, $p, 4);
				$p += 4;
			}
			if ($buflen < $p + $dataLength) {
				return; // not enough data yet
			}
			
			$data = binarySubstr($this->connection->buf, $p, $dataLength);
			$p += $dataLength;
			if ($isMasked) {
				$data = $this->mask($data, $mask);
			}
			$this->connection->buf = binarySubstr($this->connection->buf, $p);
			//Daemon::log(Debug::dump(array('ext' => $this->connection->extensions, 'rsv1' => $rsv1, 'data' => Debug::exportBytes($data))));
			if ($rsv1 && in_array('deflate-frame', $this->connection->extensions)) { // deflate frame
				$data = gzuncompress($data, $this->connection->pool->maxAllowedPacket);
			}
			if (!$fin) {
				$this->connection->framebuf .= $data;
			} else {
				$this->connection->onFrame($this->connection->framebuf . $data, $opcodeName);
				if ($this->connection) {
					$this->connection->framebuf = '';
				}
			}
		}
    }
}