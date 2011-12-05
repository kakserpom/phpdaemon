<?php

/**
 * Websocket protocol hybi-10
 * @see	http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-10
 */

class WebSocketProtocolV8 extends WebSocketProtocol
{
	// @todo manage only the 4 last bits (opcode), as described in the draft
	const STRING = 0x81;
	const BINARY = 0x82;
		
	public function __construct($session)
	{
	    parent::__construct($session) ;

		$this->description = "Websocket protocol version " . $this->session->server['HTTP_SEC_WEBSOCKET_VERSION'] . " (IETF draft 'hybi-10')" ;
	}

	public function onHandshake()
	{
        if (!isset($this->session->server['HTTP_SEC_WEBSOCKET_KEY']) || !isset($this->session->server['HTTP_SEC_WEBSOCKET_VERSION']) || ($this->session->server['HTTP_SEC_WEBSOCKET_VERSION'] != 8))
        {
            return FALSE ;
        }

		return TRUE ;
	}

    /**
     * Returns handshaked data for reply
	 * @param string Received data (no use in this class)
     * @return string Handshaked data
     */

    public function getHandshakeReply($data)
	{
        if ($this->onHandshake())
		{
            if (!isset($this->session->server['HTTP_SEC_WEBSOCKET_ORIGIN']))
			{
                $this->session->server['HTTP_SEC_WEBSOCKET_ORIGIN'] = '' ;
            }

            $reply = "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: websocket\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Origin: " . $this->session->server['HTTP_SEC_WEBSOCKET_ORIGIN'] . "\r\n"
                . "Sec-WebSocket-Location: ws://" . $this->session->server['HTTP_HOST'] . $this->session->server['REQUEST_URI'] . "\r\n"
                . "Sec-WebSocket-Accept: " . base64_encode(sha1(trim($this->session->server['HTTP_SEC_WEBSOCKET_KEY']) . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true)) . "\r\n" ;

            if (isset($this->session->server['HTTP_SEC_WEBSOCKET_PROTOCOL']))
			{
                $reply .= "Sec-WebSocket-Protocol: " . $this->session->server['HTTP_SEC_WEBSOCKET_PROTOCOL'] . "\r\n" ;
            }

            $reply .= "\r\n" ;

            return $reply ;
        }

		return FALSE ;
    }

	/**
	 * Data encoding, according to related IETF draft
	 * 
	 * @see http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-10#page-16
	 */

    protected function _dataEncode($decodedData, $type = NULL)
    {
        $frames = array() ;
        $maskingKeys = chr(rand(0, 255)) . chr(rand(0, 255)) . chr(rand(0, 255)) . chr(rand(0, 255)) ;
        $frames[0] = ($type === NULL) ? $this->getFrameType("STRING") : $this->getFrameType($type) ;
        $dataLength = strlen($decodedData) ;

        if ($dataLength <= 125)
        {
            $frames[1] = $dataLength + 128 ;
        }
        elseif ($dataLength <= 65535)
        {
            $frames[1] = 254 ; // 126 + 128
            $frames[2] = $dataLength >> 8 ;
            $frames[3] = $dataLength & 0xFF ;
        }
        else
        {
            $frames[1] = 255 ; // 127 + 128
            $frames[2] = $dataLength >> 56 ;
            $frames[3] = $dataLength >> 48 ;
            $frames[4] = $dataLength >> 40 ;
            $frames[5] = $dataLength >> 32 ;
            $frames[6] = $dataLength >> 24 ;
            $frames[7] = $dataLength >> 16 ;
            $frames[8] = $dataLength >>  8 ;
            $frames[9] = $dataLength & 0xFF ;
        }

        $maskingFunc = function($data, $mask)
        {
            for ($i = 0, $l = strlen($data); $i < $l; $i++)
            {
                // Avoid storing a new copy of $data...
                $data[$i] = $data[$i] ^ $mask[$i % 4] ;
            }
         
            return $data ;
        } ;
        
        return implode('', array_map('chr', $frames)) . $maskingKeys . $maskingFunc($decodedData, $maskingKeys) ;
    }

	/**
	 * Data decoding, according to related IETF draft
	 * 
	 * @see http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-10#page-16
	 */

    protected function _dataDecode($encodedData)
    {
        $isMasked   = (bool) (ord($encodedData[1]) >> 7) ;
        $dataLength = ord($encodedData[1]) & 127 ;
                
        if ($isMasked)
        {
            $unmaskingFunc = function($data, $mask)
            {
                for ($i = 0, $l = strlen($data); $i < $l; $i++)
                {
                    // Avoid storing a new copy of $data...
                    $data[$i] = $data[$i] ^ $mask[$i % 4] ;
                }
             
                return $data ;
            } ;
         
            if ($dataLength === 126)
            {
                $maskingKey    = binarySubstr($encodedData, 4, 4) ;
                $extDataLength = hexdec(sprintf('%02x%02x', ord($encodedData[2]), ord($encodedData[3]))) ;
                $offsetStart   = 8 ;
            }
            elseif ($dataLength === 127)
            {
                $maskingKey    = binarySubstr($encodedData, 10, 4) ;
                $extDataLength = hexdec(sprintf('%02x%02x%02x%02x%02x%02x%02x%02x', ord($encodedData[2]), ord($encodedData[3]), ord($encodedData[4]), ord($encodedData[5]), ord($encodedData[6]), ord($encodedData[7]), ord($encodedData[8]), ord($encodedData[9]))) ;
                $offsetStart   = 14 ;
            }
            else
            {
                $maskingKey    = binarySubstr($encodedData, 2, 4) ;
                $extDataLength = $dataLength ;
                $offsetStart   = 6 ;
            }

            if (strlen($encodedData) < $offsetStart + $extDataLength)
            {
                Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Incorrect data size in frame decoding for client "' . $this->session->clientAddr . '"') ; 
            }
            
            return $unmaskingFunc(binarySubstr($encodedData, $offsetStart, $extDataLength), $maskingKey) ;
        }
        else
        {
            if ($dataLength === 126)
            {
                return binarySubstr($encodedData, 4) ;
            }
            elseif ($dataLength === 127)
            {
                return binarySubstr($encodedData, 10) ;
            }
            else
            {
                return binarySubstr($encodedData, 2) ;
            }
        }

        return NULL ;
    }
}