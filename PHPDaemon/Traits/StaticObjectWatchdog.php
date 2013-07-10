<?php
namespace PHPDaemon\Traits;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/**
 * Watchdog of __set in static objects
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */

trait StaticObjectWatchdog {
	/**
	 * @param string $prop
	 * @param string $value
	 * @return null|mixed
	 */
	public function __set($prop, $value) {
		Daemon::log('[CODE WARN] Setting undefined property ' . json_encode($prop) . ' in object of class ' . get_class($this) . PHP_EOL . Debug::backtrace());
		$this->{$prop} = $value;
	}
	/**
	 * @param string $prop
	 * @return null|mixed
	 */
	public function __unset($prop) {
		Daemon::log('[CODE WARN] Unsetting property ' . json_encode($prop) . ' in object of class ' . get_class($this) . PHP_EOL . Debug::backtrace());
		unset($this->{$prop});
	}
}
