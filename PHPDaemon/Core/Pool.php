<?php
namespace PHPDaemon\Core;

use PHPDaemon\ClassFinder;
use PHPDaemon\Core\AppInstance;
use PHPDaemon\Core\Daemon;

/**
 * Pool application instance
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class Pool extends AppInstance {
	public $pool;

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * Uncomment and return array with your default options
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return false;
	}

	/**
	 * Constructor.
	 * @return void
	 */
	protected function init() {
		if ($this->isEnabled()) {
			list ($class, $name) = explode(':', $this->name . ':');
			$class = ClassFinder::find($class);
			if (!class_exists($class)) {
				Daemon::log($class . ' class not exists.');
				return;
			}
			$this->pool              = call_user_func([$class, 'getInstance'], $name);
			$this->pool->appInstance = $this;
		}
	}

	/**
	 * Function handles incoming Remote Procedure Calls
	 * You can override it
	 * @param string $method Method name.
	 * @param array $args    Arguments.
	 * @return mixed Result
	 */
	public function RPCall($method, $args) {
		if (!is_callable($f = [$this->pool, 'RPCall'])) {
			return false;
		}
		return call_user_func($f, $method, $args);
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		if ($this->pool) {
			$this->pool->onReady();
		}
	}

	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		if ($this->pool) {
			$this->pool->config = $this->config;
			$this->pool->onConfigUpdated();
		}
	}

	/**
	 * Called when application instance is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown($graceful = false) {
		if ($this->pool) {
			return $this->pool->onShutdown($graceful);
		}
		return true;
	}
}
