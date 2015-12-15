<?php
namespace PHPDaemon\Traits;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/**
 * Watchdog of __set in static objects
 * @package PHPDaemon\Traits
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
trait StrictStaticObjectWatchdog {
	/**
	 * @param  string $prop
	 * @param  mixed  $value
	 * @throws UndefinedPropertySetting if trying to set undefined property
	 * @return void
	 */
	public function __set($prop, $value) {
		throw new UndefinedPropertySetting('Trying to set undefined property ' . json_encode($prop) . ' in object of class ' . get_class($this));
	}

	/**
	 * @param  string $prop
	 * @throws UnsettingProperty if trying to unset property
	 * @return void
	 */
	public function __unset($prop) {
		throw new UnsettingProperty('Trying to unset property ' . json_encode($prop) . ' in object of class ' . get_class($this));
	}
}
