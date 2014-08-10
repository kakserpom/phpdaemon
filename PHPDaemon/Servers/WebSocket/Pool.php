<?php
namespace PHPDaemon\Servers\WebSocket;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Network\Server;
use PHPDaemon\WebSocket\Route;

class Pool extends Server {

	use \PHPDaemon\Traits\EventHandlers;
	
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
	 * Sets an array of options associated to the route
	 * @param string Route name.
	 * @param array  Options
	 * @return boolean Success.
	 */
	public function setRouteOptions($path, $opts) {
		$routeName = ltrim($path, '/');
		if (!isset($this->routes[$routeName])) {
			Daemon::log(__METHOD__ . ': Route \'' . $path . '\' is not found.');
			return false;
		}
		$this->routeOptions[$routeName] = $opts;
		return true;
	}


	/**
	 * Return options by route
	 * @param string Route name
	 * @return array Options
	 */
	public function getRouteOptions($path) {
		$routeName = ltrim($path, '/');
		if (!isset($this->routeOptions[$routeName])) {
			return [];
		}
		return $this->routeOptions[$routeName];
	}

	/**
	 * Adds a route if it doesn't exist already.
	 * @param string Route name.
	 * @param mixed  Route's callback.
	 * @return boolean Success.
	 */
	public function addRoute($path, $cb) {
		$routeName = ltrim($path, '/');
		if (isset($this->routes[$routeName])) {
			Daemon::log(__METHOD__ . ': Route \'' . $path . '\' is already defined.');
			return false;
		}
		$this->routes[$routeName] = $cb;
		return true;
	}

	public function getRoute($path, $client, $withoutCustomTransport = false) {
		if (!$withoutCustomTransport) {
			$this->trigger('customTransport', $path, $client, function($set) use (&$result) {$result = $set;});
			if ($result !== null) {
				return $result;
			}
		}
		$routeName = ltrim($path, '/');
		if (!isset($this->routes[$routeName])) {
			if (Daemon::$config->logerrors->value) {
				Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : undefined path "' . $path . '" for client "' . $client->addr . '"');
			}
			return false;
		}
		$route = $this->routes[$routeName];
		if (is_string($route)) { // if we have a class name
			if (class_exists($route)) {
				$this->onWakeup();
				$ret = new $route($client);
				$this->onSleep();
				return $ret;
			}
			else {
				return false;
			}
		}
		elseif (is_array($route) || is_object($route)) { // if we have a lambda object or callback reference
			if (!is_callable($route)) {
				return false;
			}
			$ret = call_user_func($route, $client); // calling the route callback
			if (!$ret instanceof Route) {
				return false;
			}
			return $ret;
		}
		else {
			return false;
		}
	}

	/**
	 * Force add/replace a route.
	 * @param string Path
	 * @param mixed  callback
	 * @return boolean Success
	 */
	public function setRoute($path, $cb) {
		$routeName = ltrim($path, '/');
		$this->routes[$routeName] = $cb;
		return true;
	}

	/**
	 * Removes a route.
	 * @param string Route name
	 * @return boolean Success
	 */
	public function removeRoute($path) {
		$routeName = ltrim($path, '/');
		if (!isset($this->routes[$routeName])) {
			return false;
		}
		unset($this->routes[$routeName]);
		return true;
	}

	/**
	 * Checks if route exists
	 * @param string Route name
	 * @return boolean Exists?
	 */
	public function routeExists($path) {
		$routeName = ltrim($path, '/');
		return isset($this->routes[$routeName]);
	}
}
