<?php
namespace PHPDaemon\Config\Entry;

use PHPDaemon\Config\Entry\Generic;

/**
 * Boolean config entry
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Boolean extends Generic {

	/**
	 * @param $value
	 * @return bool
	 */
	public static function HumanToPlain($value) {
		return (boolean)$value;
	}

}
