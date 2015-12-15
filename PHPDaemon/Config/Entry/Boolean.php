<?php
namespace PHPDaemon\Config\Entry;

use PHPDaemon\Config\Entry\Generic;

/**
 * Boolean config entry
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Boolean extends Generic {

	/**
	 * Converts human-readable value to plain
	 * @param $value
	 * @return bool
	 */
	public static function HumanToPlain($value) {
		return (boolean)$value;
	}

}
