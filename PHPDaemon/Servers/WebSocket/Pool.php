<?php
namespace PHPDaemon\Servers\WebSocket;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Network\Server;
use PHPDaemon\WebSocket\Route;

class Pool extends Server {
	/** @var array */
	public $routes = [];

	/**
	 * Binary packet type
	 * @var string
	 */
	const BINARY = 'BINARY';
	
	/**
	 * String packet type
	 * @var string
	 */
	const STRING = 'STRING';

	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			// @todo add description strings
			'expose'             => 1,
			'listen'             => '0.0.0.0',
			'port'               => 8047,
			'max-allowed-packet' => new \PHPDaemon\Config\Entry\Size('1M'),
			'fps-name'           => '',
		];
	}

	/**
	 * Adds a route if it doesn't exist already.
	 * @param string Route name.
	 * @param mixed  Route's callback.
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

	public function getRoute($route, $client) {
		if (!isset($this->routes[$route])) {
			return false;
		}
		return call_user_func($this->routes[$route], $client);
	}

	/**
	 * Force add/replace a route.
	 * @param string Route name.
	 * @param mixed  Route's callback.
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
	public function removeRoute($route) {
		if (!isset($this->routes[$route])) {
			return false;
		}
		unset($this->routes[$route]);
		return true;
	}
}
