<?php

class WebSocketServer extends AsyncServer
{
	public $sessions = array();
	public $routes = array();

	protected $timeout_cb;

	const BINARY = 'BINARY';
	const STRING = 'STRING';

	/**
	 * Registering event timeout callback function
	 * @param Closure Callback function
	 * @return void
	 */

	public function registerEventTimeout($cb)
	{
		if ($cb === NULL || is_callable($cb))
		{
			$this->timeout_cb = $cb ;
		}
	}

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */

	protected function getConfigDefaults()
	{
		return array(
			// listen to
			'listen'     => 'tcp://0.0.0.0',
			// listen port
			'listenport' => 8047,
			// max allowed packet size
			'maxallowedpacket' => new Daemon_ConfigEntrySize('16k'),
			// disabled by default
			'enable'     => 0,
			// no event_timeout by default
			'ev_timeout' => -1
		);
	}

	/**
	 * Event of appInstance. Adds default settings and binds sockets.
	 * @return void
	 */

	public function init() {
		
		if ($this->config->enable->value)
		{
			$this->bindSockets(
				$this->config->listen->value,
				$this->config->listenport->value
			);
		}
	}
	
    /**
     * Enable all events of sockets
     * @return void
     */

    public function enableSocketEvents()
	{
        foreach ($this->socketEvents as $ev)
		{
            event_base_set($ev, Daemon::$process->eventBase);
            event_add($ev, $this->config->ev_timeout->value); // With specified timeout
        }
    }

	/**
	 * Called when a request to HTTP-server looks like WebSocket handshake query.
	 * @return void
	 */

	public function inheritFromRequest($req, $appInstance)
	{
		$connId = $req->attrs->connId;
		
		unset(Daemon::$process->queue[$connId . '-' . $req->attrs->id]);
		
		$this->buf[$connId] = $appInstance->buf[$connId];
		
		unset($appInstance->buf[$connId]);
		unset($appInstance->poolState[$connId]);
		
		$set = event_buffer_set_callback(
			$this->buf[$connId], 
			$this->directReads ? NULL : array($this, 'onReadEvent'),
			array($this, 'onWriteEvent'),
			array($this, 'onFailureEvent'),
			array($connId)
		);
		
		unset(Daemon::$process->readPoolState[$connId]);
		
		$this->poolState[$connId] = array();
		
		$this->sessions[$connId] = new WebSocketSession($connId, $this);
		$this->sessions[$connId]->clientAddr = $req->attrs->server['REMOTE_ADDR'];
		$this->sessions[$connId]->server = $req->attrs->server;
		$this->sessions[$connId]->firstline = TRUE;
		$this->sessions[$connId]->stdin("\r\n" . $req->attrs->inbuf);
	}

	/**
	 * Adds a route if it doesn't exist already.
	 * @param string Route name.
	 * @param mixed Route's callback.
	 * @return boolean Success.
	 */

	public function addRoute($route, $cb)
	{
		if (isset($this->routes[$route]))
		{
			Daemon::log(__METHOD__ . ' Route \'' . $route . '\' is already taken.');
			return FALSE;
		}
		
		$this->routes[$route] = $cb;

		return TRUE;
	}
	
	/**
	 * Force add/replace a route.
	 * @param string Route name.
	 * @param mixed Route's callback.
	 * @return boolean Success.
	 */

	public function setRoute($route, $cb)
	{
		$this->routes[$route] = $cb;
	
		return TRUE;
	}
	
	/**
	 * Removes a route.
	 * @param string Route name.
	 * @return boolean Success.
	 */

	public function removeRoute($route)
	{
		if (!isset($this->routes[$route]))
		{
			return FALSE;
		}

		unset($this->routes[$route]);

		return TRUE;
	}
	
	/**
	 * Event of appInstance.
	 * @return void
	 */

	public function onReady()
	{
		if ($this->config->enable->value)
		{
			$this->enableSocketEvents();
		}
	}

    /**
     * Called when remote host is trying to establish the connection
     * @param resource Descriptor
     * @param integer Events
     * @param mixed Attached variable
     * @return boolean If true then we can accept new connections, else we can't
     */

    public function checkAccept($stream, $events, $arg)
	{
		if (!parent::checkAccept($stream, $events, $arg))
		{
            return FALSE;
        }

		$sockId = $arg[0];

		event_add($this->socketEvents[$sockId], $this->config->ev_timeout->value) ; // With specified timeout

		// Always return FALSE to skip adding event without timeout in "parent::onAcceptEvent"...
		return FALSE ;
    }

    /**
     * Called when remote host is trying to establish the connection
     * @param integer Connection's ID
     * @param string Address
     * @return boolean Accept/Drop the connection
     */

    public function onAccept($connId, $addr)
	{
		if (parent::onAccept($connId, $addr))
		{
			return TRUE ;
		}

		return FALSE ;
    }

	/**
	 * Event of asyncServer
	 * @param integer Connection's ID
	 * @param string Peer's address
	 * @return void
	 */
	protected function onAccepted($connId, $addr)
	{
		$this->sessions[$connId] = new WebSocketSession($connId, $this);
		$this->sessions[$connId]->clientAddr = $addr;
	}

    /**
     * Called when new connections is waiting for accept
     * @param resource Descriptor
     * @param integer Events
     * @param mixed Attached variable
     * @return void
     */

    public function onAcceptEvent($stream, $events, $arg)
	{
		if ($events & EV_TIMEOUT)
		{
			$sockId = $arg[0];

			if ($this->timeout_cb !== NULL)
			{
				call_user_func($this->timeout_cb) ;
			}

			event_add($this->socketEvents[$sockId], $this->config->ev_timeout->value) ;
			return ;
		}

		parent::onAcceptEvent($stream, $events, $arg);
	}	
/*
	public function onTimeout()
	{

	}
*/
}

