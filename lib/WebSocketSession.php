<?php
class WebSocketSession extends SocketSession {
	
	public $secprotocol;
	public $resultKey;
	public $handshaked = FALSE;
	public $upstream;
	public $server = array();
	public $cookie = array();
	public $firstline = FALSE;
	public $writeReady = TRUE;
	public $callbacks = array();

	public $protocol; // Related WebSocket protocol

	public function init()
	{
	}
	
	/**
	 * Sends a frame.
	 * @param string Frame's data.
	 * @param string Frame's type. ("STRING" OR "BINARY")
	 * @param callback Optional. Callback called when the frame is received by client.
	 * @return boolean Success.
	 */

	public function sendFrame($data, $type = NULL, $callback = NULL)
	{
		if (!$this->handshaked)
		{
			return FALSE;
		}

        if (!isset($this->protocol))
        {
            Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot find session-related websocket protocol for client ' . $this->clientAddr) ;
            return FALSE ;
        }

        $this->protocol->sendFrame($data, $type) ;
		$this->writeReady = FALSE;

		if ($callback)
		{
			$this->callbacks[] = $callback;
		}

		return TRUE;
	}

	/**
	 * Event of SocketSession (asyncServer).
	 * @return void
	 */

	public function onFinish()
	{
		if (Daemon::$config->logevents->value)
		{
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' invoked');
		}
		
		if (isset($this->upstream))
		{
			$this->upstream->onFinish();
		}
		
		unset($this->upstream);
		unset($this->appInstance->sessions[$this->connId]);
	}
	
	/**
	 * Called when new frame received.
	 * @param string Frame's data.
	 * @param string Frame's type ("STRING" OR "BINARY").
	 * @return boolean Success.
	 */

	public function onFrame($data, $type)
	{
		if (Daemon::$config->logevents->value)
		{
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' invoked');
		}
		
		if (!isset($this->upstream))
		{
			return FALSE;
		}

		$this->upstream->onFrame($data, $type);

		return TRUE;
	}
	
	/**
	 * Called when the connection is ready to accept new data.
	 * @return void
	 */

	public function onWrite()
	{
		if (Daemon::$config->logevents->value)
		{
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' invoked');
		}
		
		$this->writeReady = TRUE;
		
		for ($i = 0, $s = sizeof($this->callbacks); $i < $s; ++$i)
		{
			call_user_func(array_shift($this->callbacks), $this);
		}
		
		if (is_callable(array($this->upstream, 'onWrite')))
		{
			$this->upstream->onWrite();
		}
	}
	
	/**
	 * Called when the connection is handshaked.
	 * @return boolean Ready to handshake ?
	 */

	public function onHandshake()
	{
		if (Daemon::$config->logevents->value)
		{
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' invoked');
		}
		
		$e = explode('/', $this->server['DOCUMENT_URI']);
		$appName = isset($e[1])?$e[1]:'';

		if (!isset($this->appInstance->routes[$appName]))
		{
			if (Daemon::$config->logerrors->value)
			{
				Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : undefined route "' . $appName . '" for client "' . $this->clientAddr . '"');
			}

			return FALSE;
		}

		if (!$this->upstream = call_user_func($this->appInstance->routes[$appName], $this))
		{
			return FALSE;
		}

        if (!isset($this->protocol))
        {
            Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot find session-related websocket protocol for client "' . $this->clientAddr . '"') ;
            return FALSE ;
        }

		if ($this->protocol->onHandshake() === FALSE)
		{
			return FALSE ;
		}		

		return TRUE;
	}
	
	/**
	 * Event of SocketSession (AsyncServer). Called when the worker is going to shutdown.
	 * @return boolean Ready to shutdown ?
	 */

	public function gracefulShutdown()
	{
		if ((!$this->upstream) || $this->upstream->gracefulShutdown())
		{
			$this->finish();

			return TRUE;
		}

		return FALSE;
	}

	/**
	 * Called when we're going to handshake.
	 * @return boolean Handshake status
	 */

	public function handshake($data)
	{
		$this->handshaked = TRUE;

		if (!$this->onHandshake())
		{
			$this->finish() ;
			return FALSE ;
		}

		if (!isset($this->protocol))
		{
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot find session-related websocket protocol for client "' . $this->clientAddr . '"') ;
			$this->finish() ;
		}

		// Handshaking...
		$handshake = $this->protocol->getHandshakeReply($data);

		if (!$handshake)
		{
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Handshake protocol failure for client "' . $this->clientAddr . '"') ;
			$this->finish() ;
			return FALSE ;
		}
		if ($this->write($handshake))
		{
			if (is_callable(array($this->upstream, 'onHandshake')))
			{
				$this->upstream->onHandshake();
			}
		}
		else
		{
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Handshake send failure for client "' . $this->clientAddr . '"') ;
			$this->finish() ;
			return FALSE ;
		}

		return TRUE ;
	}
	
	/**
	 * Event of SocketSession (AsyncServer). Called when new data received.
	 * @param string New received data.
	 * @return void
	 */

	public function stdin($buf)
	{
		$this->buf .= $buf;
		if (!$this->handshaked)
		{
			if (Daemon::$appResolver->checkAppEnabled('FlashPolicy'))
			{
				if (strpos($this->buf, '<policy-file-request/>') !== FALSE) {
					if (
						($FP = Daemon::$appResolver->getInstanceByAppName('FlashPolicy')) 
						&& $FP->policyData
					) {
						$this->write($FP->policyData . "\x00");
					}

					$this->finish();

					return;
				}
			}

			$i = 0;

			while (($l = $this->gets()) !== FALSE)
			{
				if ($i++ > 100)
				{
					break;
				}

				if ($l === "\r\n")
				{
					if (
							!isset($this->server['HTTP_CONNECTION']) 
						|| (!preg_match('~(?:^|\W)Upgrade(?:\W|$)~i', $this->server['HTTP_CONNECTION']))  // "Upgrade" is not always alone (ie. "Connection: Keep-alive, Upgrade")
						||	!isset($this->server['HTTP_UPGRADE']) 
						|| (strtolower($this->server['HTTP_UPGRADE']) !== 'websocket')    // Lowercase compare important
					) {
						$this->finish();
						return;
					}

					if (isset($this->server['HTTP_COOKIE']))
					{
						HTTPRequest::parse_str(strtr($this->server['HTTP_COOKIE'], HTTPRequest::$hvaltr), $this->cookie);
					}

					// ----------------------------------------------------------
					// Protocol discovery, based on HTTP headers...
					// ----------------------------------------------------------
					if (isset($this->server['HTTP_SEC_WEBSOCKET_VERSION']))	// HYBI
					{
						if ($this->server['HTTP_SEC_WEBSOCKET_VERSION'] == '8') // Version 8 (FF7, Chrome14)
						{
							$this->protocol = new WebSocketProtocolV8($this) ;
						}
						elseif ($this->server['HTTP_SEC_WEBSOCKET_VERSION'] == '13') // newest protocol
						{
							$this->protocol = new WebSocketProtocolV13($this);
						}
						else
						{
							Daemon::$process->log(get_class($this) . '::' . __METHOD__ . " : Websocket protocol version " . $this->server['HTTP_SEC_WEBSOCKET_VERSION'] . ' is not yet supported for client "' . $this->clientAddr . '"') ;

							$this->finish();
							return;
						}
					}
					else	// Defaulting to HIXIE (Safari5 and many non-browser clients...)
					{
						$this->protocol = new WebSocketProtocolV0($this) ;
					}
					// ----------------------------------------------------------
					// End of protocol discovery
					// ----------------------------------------------------------
					$this->handshake($this->buf);
					break;
				}

				if (!$this->firstline)
				{
					$this->firstline = TRUE;     
					$e = explode(' ', $l);
					$u = parse_url(isset($e[1]) ? $e[1] : '');

					$this->server['REQUEST_METHOD'] = $e[0];
					$this->server['REQUEST_URI'] = $u['path'] . (isset($u['query']) ? '?' . $u['query'] : '');
					$this->server['DOCUMENT_URI'] = $u['path'];
					$this->server['PHP_SELF'] = $u['path'];
					$this->server['QUERY_STRING'] = isset($u['query']) ? $u['query'] : NULL;
					$this->server['SCRIPT_NAME'] = $this->server['DOCUMENT_URI'] = isset($u['path']) ? $u['path'] : '/';
					$this->server['SERVER_PROTOCOL'] = isset($e[2]) ? trim($e[2]) : '';

					list($this->server['REMOTE_ADDR'],$this->server['REMOTE_PORT']) = explode(':', $this->clientAddr);
				}
				else
				{
					$e = explode(': ', $l);
					
					if (isset($e[1]))
					{
						$this->server['HTTP_' . strtoupper(strtr($e[0], HTTPRequest::$htr))] = rtrim($e[1], "\r\n");
					}
				}
			}
		}
	
		if ($this->handshaked)
		{
	        if (!isset($this->protocol))
    	    {
        	    Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot find session-related websocket protocol for client "' . $this->clientAddr . '"');
            	$this->finish();
            	return;
	        }
	        $this->protocol->onRead();
		}
	}
}
