<?php

class WebSocketServer extends NetworkServer
{
	public $routes = array();

	const BINARY = 'BINARY';
	const STRING = 'STRING';
	
	public $listen = 'tcp://0.0.0.0';
	public $defaultPort = 8047;
	public $maxAllowedPacket = 16384;

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
		if (!isset($this->routes[$route])) {
			return false;
		}
		unset($this->routes[$route]);
		return true;
	}
}

