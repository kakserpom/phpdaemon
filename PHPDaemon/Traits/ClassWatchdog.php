<?php
namespace PHPDaemon\Traits;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Exceptions\UndefinedMethodCalled;

/**
 * Watchdog of __call and __callStatic
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */

trait ClassWatchdog {
	/**
	 * @param string $method
	 * @param array $args
	 * @return null|mixed
	 */
	public function __call($method, $args) {
		throw new UndefinedMethodCalled('Call to undefined method ' . get_class($this) . '->' . $method);
	}

	/**
	 * @param string $method
	 * @param array $args
	 * @return null|mixed
	 */
	public static function __callStatic($method, $args) {
		throw new UndefinedMethodCalled('Call to undefined static method ' . get_called_class() . '::' . $method);
	}
}
