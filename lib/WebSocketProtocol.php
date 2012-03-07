<?php

/**
 * Websocket protocol abstract class
 */

abstract class WebSocketProtocol
{
	public $description ;
	protected $session ;

	const STRING = NULL;
	const BINARY = NULL;
		
	public function __construct($session)
	{
		$this->session = $session ;
	}

	public function getFrameType($type)
	{
	    $frametype = @constant(get_class($this) .'::' . $type) ;
	    
	    if ($frametype === NULL)
	    {
	        Daemon::log(__METHOD__ . ' : Undefined frametype "' . $type . '"') ;
	    }
	    
	    return $frametype ; 
	}
	
	public function onHandshake()
	{
		return TRUE ;
	}

	public function sendFrame($data, $type)
	{
	    $this->session->write($this->_dataEncode($data, $type)) ;
	}

	public function recvFrame($data, $type)
	{
        $this->session->onFrame($this->_dataDecode($data), $type) ;
	    $this->session->buf = "" ;
	}
	
    /**
     * Returns handshaked data for reply
	 * @param string Received data (no use in this class)
     * @return string Handshaked data
     */

    public function getHandshakeReply($data)
	{
		return FALSE ;
    }

	/**
	 * Data encoding
	 */

    protected function _dataEncode($decodedData, $type = NULL)
    {
        return NULL ;
    }

	/**
	 * Data decoding
	 */

    protected function _dataDecode($encodedData)
    {
       return NULL ;
    }
}