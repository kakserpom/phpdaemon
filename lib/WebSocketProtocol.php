<?php

/**
 * Websocket protocol abstract class
 */

class WebSocketProtocol
{
	public $description ;
	protected $session ;

	const STRING = NULL;
	const BINARY = NULL;
		
	public function __construct($session)
	{
		$this->session = $session;
	}

	public function getFrameType($type)
	{
		if ($type === NULL) {
			$type = 'STRING';
		}
	    $frametype = @constant($a = get_class($this) .'::' . $type) ;
	    
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
	    $this->session->write($this->encodeFrame($data, $type)) ;
	}

	public function onRead() {
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

}