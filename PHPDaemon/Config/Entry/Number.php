<?php
namespace PHPDaemon\Config\Entry;

use PHPDaemon\Config\Entry\Generic;

/**
 * Number config entry
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Number extends Generic {

	/**
	 * Converts human-readable value to plain
	 * @param $value
	 * @return int|null
	 */
	public static function HumanToPlain($value) {
		if ($value === null) {
			return null;
		}
		$l = substr($value, -1);

		if (
				($l === 'k')
				|| ($l === 'K')
		) {
			return ((int)substr($value, 0, -1) * 1000);
		}

		if (
				($l === 'm')
				|| ($l === 'M')
		) {
			return ((int)substr($value, 0, -1) * 1000 * 1000);
		}

		if (
				($l === 'g')
				|| ($l === 'G')
		) {
			return ((int)substr($value, 0, -1) * 1000 * 1000 * 1000);
		}
		return (int)$value;
	}

}
