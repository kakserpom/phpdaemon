<?php
namespace PHPDaemon\Daemon;

/**
 * External function config entry
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class ConfigEntryExtFunc extends ConfigEntry {

	public static function HumanToPlain($value) {
		$cb = include($value);
		return is_callable($cb) ? $cb : null;
	}

}
