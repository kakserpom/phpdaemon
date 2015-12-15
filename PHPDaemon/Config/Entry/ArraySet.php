<?php
namespace PHPDaemon\Config\Entry;

use PHPDaemon\Config\Entry\Generic;

/**
 * Array config entry
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class ArraySet extends Generic {

	/**
	 * Converts human-readable value to plain
	 * @param array|string $value
	 * @return array
	 */
	public static function HumanToPlain($value) {
		if (is_array($value)) {
			return $value;
		}
		$value = preg_replace_callback('~(".*?")|(\'.*?\')|(\s*,\s*)~s', function ($m) {
			if (!empty($m[3])) {
				return "\x00";
			}
			if (!empty($m[2])) {
				return substr($m[2], 1, -1);
			}
			if (!empty($m[1])) {
				return substr($m[1], 1, -1);
			}
			return null;
		}, $value);
		return explode("\x00", $value);
	}

}
