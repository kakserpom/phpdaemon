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

trait StrictStaticObjectWatchdog {
	/**
	 * @param string $prop
	 * @param string $value
	 * @return null|mixed
	 */
	public function __set($prop, $value) {
		throw new UndefinedPropertySetting('Trying to set undefined property ' . json_encode($prop) . ' in object of class ' . get_class($this));
	}

	/**
	 * @param string $prop
	 * @return null|mixed
	 */
	public function __unset($prop) {
		throw new UnsettingProperty('Trying to unset property ' . json_encode($prop) . ' in object of class ' . get_class($this));
	}
}
