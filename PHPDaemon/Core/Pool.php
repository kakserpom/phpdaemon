<?php
namespace PHPDaemon\Core;

use PHPDaemon\Core\AppInstance;
use PHPDaemon\Core\ClassFinder;
use PHPDaemon\Core\Daemon;

/**
 * Pool application instance
 *
 * @package Core
 *
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class Pool extends AppInstance {
	/**
	 * @var Pool
	 */
	public $pool;

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * Uncomment and return array with your default options
	 * @return boolean
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
			$realclass = ClassFinder::find($class);
			$e = explode('\\', $realclass);
			if (($e[sizeof($e) - 1] !== 'Pool') && class_exists($realclass . '\\Pool')) {
				$realclass .= '\\Pool';
			}
			if ($realclass !== $class) {
				$base = '\\PHPDaemon\\Core\\Pool:';
				Daemon::$config->renameSection($base . $class . ($name !== '' ? ':' . $name : ''), $base . $realclass . ($name !== '' ? ':' . $name : ''));
			}
			if (!class_exists($realclass)) {
				Daemon::log($realclass . ' class not exists.');
				return;
			}
			$this->pool = call_user_func([$realclass, 'getInstance'], $name);
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
