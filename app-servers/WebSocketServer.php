<?php

class WebSocketServer extends AppInstance
{
	public $pool;
	public $routes = array();

	const BINARY = 'BINARY';
	const STRING = 'STRING';

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
		);
	}

	/**
	 * Event of appInstance. Adds default settings and binds sockets.
	 * @return void
	 */

	public function init() {
		if ($this->config->enable->value) {
			$this->pool = new ConnectionPool('WebSocketConnection', $this->config->listen->value, $this->config->listenport->value);
			$this->pool->appInstance = $this;
		}
	}

	/**
	 * Called when a request to HTTP-server looks like WebSocket handshake query.
	 * @return void
	 */

	public function inheritFromRequest($req, $appInstance) {
		// very hacky code, must be refactored
		$connId = $req->attrs->connId;
		
		unset(Daemon::$process->queue[$connId . '-' . $req->attrs->id]);
		
		$buf = $appInstance->buf[$connId];
		
		unset($appInstance->buf[$connId]);
		unset($appInstance->poolState[$connId]);		
		unset(Daemon::$process->readPoolState[$connId]);
		
		$conn = new WebSocketConnection($connId, null, $req->attrs->server['REMOTE_ADDR'], $this->pool);
		$this->pool->storage[$connId] = $conn;
		$conn->buffer = $buf;
		$set = event_buffer_set_callback(
			$buf, 
			array($conn, 'onReadEvent'),
			array($conn, 'onWriteEvent'),
			array($conn, 'onFailureEvent'),
			array($connId)
		);
		$conn->clientAddr = $req->attrs->server['REMOTE_ADDR'];
		$conn->server = $req->attrs->server;
		$conn->firstline = true;
		$conn->stdin("\r\n" . $req->attrs->inbuf);
	}

	/**
	 * Adds a route if it doesn't exist already.
	 * @param string Route name.
	 * @param mixed Route's callback.
	 * @return boolean Success.
	 */

	public function addRoute($route, $cb) {
		if (isset($this->routes[$route])) {
			Daemon::log(__METHOD__ . ' Route \'' . $route . '\' is already defined.');
			return false;
		}
		$this->routes[$route] = $cb;
		return true;
	}
	
	/**
	 * Force add/replace a route.
	 * @param string Route name.
	 * @param mixed Route's callback.
	 * @return boolean Success.
	 */

	public function setRoute($route, $cb) {
		$this->routes[$route] = $cb;
		return true;
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
			return false;
		}

		unset($this->routes[$route]);

		return true;
	}

	/**
	 * Called when the worker is ready to go
	 * @return void
	 */
	public function onReady() {
		if (isset($this->pool)) {
			$this->pool->enable();
		}
	}
}

