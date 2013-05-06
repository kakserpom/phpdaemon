<?php
namespace PHPDaemon\Daemon;

/**
 * Boolean config entry
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class ConfigEntryBoolean extends ConfigEntry {

	public static function HumanToPlain($value) {
		return (boolean)$value;
	}

}
