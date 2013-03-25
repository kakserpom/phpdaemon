<?php

/**
 * Websocket protocol hixie-76
 * @see	http://tools.ietf.org/html/draft-hixie-thewebsocketprotocol-76
 */

class WebSocketProtocolV0 extends WebSocketProtocol {

	const STRING = 0x00;
	const BINARY = 0x80;

    public function onHandshake() {
		if (!isset($this->conn->server['HTTP_SEC_WEBSOCKET_KEY1']) || !isset($this->conn->server['HTTP_SEC_WEBSOCKET_KEY2'])) {
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
			if (strlen($data) < 8) {
				return 0; // not enough data yet;
			}
			$final_key = $this->_computeFinalKey($this->conn->server['HTTP_SEC_WEBSOCKET_KEY1'], $this->conn->server['HTTP_SEC_WEBSOCKET_KEY2'], $data) ;

			if (!$final_key) {
				return false;
			}

            if (!isset($this->conn->server['HTTP_SEC_WEBSOCKET_ORIGIN'])) {
                $this->conn->server['HTTP_SEC_WEBSOCKET_ORIGIN'] = '' ;
            }

            $reply = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n"
                . "Upgrade: WebSocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Origin: " . $this->conn->server['HTTP_ORIGIN'] . "\r\n"
                . "Sec-WebSocket-Location: ws://" . $this->conn->server['HTTP_HOST'] . $this->conn->server['REQUEST_URI'] . "\r\n" ;
			if ($this->conn->pool->config->expose->value) {
				$reply .= 'X-Powered-By: phpDaemon/' . Daemon::$version . "\r\n";
			}
            if (isset($this->conn->server['HTTP_SEC_WEBSOCKET_PROTOCOL'])) {
				$reply .= "Sec-WebSocket-Protocol: " . $this->conn->server['HTTP_SEC_WEBSOCKET_PROTOCOL'] . "\r\n";
            }
            $reply .= "\r\n" . $final_key;
            return $reply;
        }

        return false;
    }

	/**
	 * Computes final key for Sec-WebSocket.
	 * @param string Key1
	 * @param string Key2
	 * @param string Data
	 * @return string Result
	 */

	protected function _computeFinalKey($key1, $key2, $data) {
		if (strlen($data) < 8) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Invalid handshake data for client "' . $this->conn->addr . '"') ;
			return false;
		}
		$bodyData = binarySubstr($data, 0, 8) ;	
		return md5($this->_computeKey($key1) . $this->_computeKey($key2) . binarySubstr($data, 0, 8), true);
	}

	/**
	 * Computes key for Sec-WebSocket.
	 * @param string Key
	 * @return string Result
	 */

	protected function _computeKey($key) {
		$spaces = 0;
		$digits = '';
	
		for ($i = 0, $s = strlen($key); $i < $s; ++$i) {
			$c = binarySubstr($key, $i, 1);

			if ($c === "\x20") {
				++$spaces;
			}
			elseif (ctype_digit($c)) {
				$digits .= $c;
			}
		}
		
		if ($spaces > 0) {
			$result = (float) floor($digits / $spaces);
		} else {
			$result = (float) $digits;
		}
		
		return pack('N', $result);
	}

    public function encodeFrame($data, $type) {
		// Binary
		$type = $this->getFrameType($type);
		if (($type & self::BINARY) === self::BINARY)
		{
			$n = strlen($data);
			$len = '';
			$pos = 0;

			char:

			++$pos;
			$c = $n >> 0 & 0x7F;
			$n = $n >> 7;

			if ($pos != 1) {
				$c += 0x80;
			}
			
			if ($c != 0x80) {
				$len = chr($c) . $len;
				goto char;
			};
			
			return chr(self::BINARY) . $len . $data ;
		}
		// String
		else {
			return chr(self::STRING) . $data . "\xFF" ;
		}
    }

    public function onRead() {
		while ($this->conn && (($buflen = $this->conn->getInputLength()) >= 2)) {
			$hdr = $this->conn->look(10);
			$frametype = ord(binarySubstr($hdr, 0, 1)) ;
			if (($frametype & 0x80) === 0x80) {
				$len = 0;
				$i = 0 ;
				do {
					if ($buflen < $i + 1) {
						// not enough data yet
						return;
					}
					$b = ord(binarySubstr($hdr, ++$i, 1));
					$n = $b & 0x7F;
					$len *= 0x80;
					$len += $n;
				} while ($b > 0x80);

				if ($this->conn->pool->maxAllowedPacket <= $len) {
					// Too big packet
					$this->conn->finish();
					return;
				}

				if ($buflen < $len + $i + 1) {
					// not enough data yet
					return;
				}
				$this->conn->drain($i + 1);
				$this->conn->onFrame($this->conn->read($len), 'BINARY');
			}
			else {
				if (($p = $this->conn->search("\xFF")) !== false) {
					if ($this->conn->pool->maxAllowedPacket <= $p - 1) {
						// Too big packet
						$this->conn->finish();
						return;
					}
					$this->conn->drain(1);
					$data = $this->conn->read($p);
					$this->conn->drain(1);
					$this->conn->onFrame($data, 'STRING');
				}
				else {
					if ($this->conn->pool->maxAllowedPacket < $buflen - 1) {
						// Too big packet
						$this->conn->finish();
						return;
					}
					// not enough data yet					
					return;
				}
			}
		}
    }
}