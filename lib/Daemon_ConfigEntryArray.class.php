<?php

/**
 * Array config entry
 *
 * @package Core
 * @subpackage Config
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon_ConfigEntryArray extends Daemon_ConfigEntry {

	public function HumanToPlain($value) {
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
		}, $value);
		return explode("\x00", $value);
	}

}
