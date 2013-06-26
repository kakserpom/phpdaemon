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
	 * @param string $event
	 * @return null|mixed
	 */
	public function __set($prop, $value) {
		Daemon::log('[CODE WARN] Creating ' . json_encode($prop) . ' property in object of class "' . get_class($this) . '"' . PHP_EOL . Debug::backtrace());
		$this->{$prop} = $value;
	}
}
