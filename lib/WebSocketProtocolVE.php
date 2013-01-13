<?php

/**
 * Websocket protocol hixie-76
 * @see    http://tools.ietf.org/html/draft-hixie-thewebsocketprotocol-76
 * @description Deprecated websocket protocol (IETF drafts 'hixie-76' or 'hybi-00')
 */

class WebSocketProtocolVE extends WebSocketProtocol {
    const STRING = 0x00;
    const BINARY = 0x80;
 
    public function onHandshake() {
        return true;
    }

    /**
     * Returns handshaked data for reply
     * @param string Received data (no use in this class)
     * @return string Handshaked data
     */

    public function getHandshakeReply($data) {
        if ($this->onHandshake()) {
            if (!isset($this->connection->server['HTTP_SEC_WEBSOCKET_ORIGIN'])) {
                $this->connection->server['HTTP_SEC_WEBSOCKET_ORIGIN'] = '';
            }

            $reply = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n"
                . "Upgrade: WebSocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Origin: " . $this->connection->server['HTTP_ORIGIN'] . "\r\n"
                . "Sec-WebSocket-Location: ws://" . $this->connection->server['HTTP_HOST'] . $this->connection->server['REQUEST_URI'] . "\r\n";
			if ($this->connection->pool->config->expose->value) {
				$reply .= 'X-Powered-By: phpDaemon/' . Daemon::$version . "\r\n";
			}
            if (isset($this->connection->server['HTTP_SEC_WEBSOCKET_PROTOCOL'])) {
                $reply .= "Sec-WebSocket-Protocol: " . $this->connection->server['HTTP_SEC_WEBSOCKET_PROTOCOL'] . "\r\n";
            }
            $reply .= "\r\n";
            return $reply;
        }
        return false;
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

            if ($pos != 1)
            {
                $c += 0x80;
            }
            
            if ($c != 0x80)
            {
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
		while ($this->connection && (($buflen = strlen($this->connection->buf)) >= 2)) {
			$frametype = ord(binarySubstr($this->connection->buf, 0, 1)); 
            if (($frametype & 0x80) === 0x80) {
                $len = 0 ;
                $i = 0 ;

                do {
                    $b = ord(binarySubstr($this->connection->buf, ++$i, 1));
                    $n = $b & 0x7F ;
                    $len *= 0x80 ;
                    $len += $n ;
                } while ($b > 0x80) ;

                if ($this->connection->pool->maxAllowedPacket <= $len)
                {
                    // Too big packet
                    $this->connection->finish() ;
                    return FALSE ;
                }

                if ($buflen < $len + 2)
                {
                    // not enough data yet
                    return FALSE ;
                } 
                    
                $decodedData = binarySubstr($this->connection->buf, 2, $len) ;
                $this->connection->buf = binarySubstr($this->connection->buf, 2 + $len) ;
                  
                $this->connection->onFrame($decodedData, $frametype);
            }
            else {
            	if (($p = strpos($this->connection->buf, "\xFF")) !== FALSE) {
                    if ($this->connection->pool->maxAllowedPacket <= $p - 1) {                   {
                        // Too big packet
                        $this->connection->finish() ;
                        return FALSE ;
                    }
                        
                    $decodedData = binarySubstr($this->connection->buf, 1, $p - 1) ;                    
                    $this->connection->buf = binarySubstr($this->connection->buf, $p + 1) ;                    
                    $this->connection->onFrame($decodedData, $frametype);
                    
                }
                else {
                    if ($this->connection->pool->maxAllowedPacket <= strlen($this->connection->buf))
                    {
                        // Too big packet
                        $this->connection->finish() ;
                        return FALSE ;
                    }
                }
            }
        }
    }
}
