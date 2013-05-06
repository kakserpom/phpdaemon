<?php

/**
 * External function config entry
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Daemon_ConfigEntryExtFunc extends Daemon_ConfigEntry {

	public static function HumanToPlain($value) {
		$cb = include($value);
		return is_callable($cb) ? $cb : null;
	}

}
