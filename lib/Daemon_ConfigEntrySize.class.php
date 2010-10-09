<?php

/**
 * Size config entry
 *
 * @package Core
 * @subpackage Config
 * @author kak.serpom.po.yaitsam@gmail.com
 */

class Daemon_ConfigEntrySize extends Daemon_ConfigEntry {

	public function HumanToPlain($value) {
		$l = strtolower(substr($value, -1));

		if ($l === 'b') {
			return ((int) substr($value, 0, -1));
		}

		if ($l === 'k') {
			return ((int) substr($value, 0, -1) * 1024);
		}

		if ($l === 'm') {
			return ((int) substr($value, 0, -1) * 1024 * 1024);
		}

		if ($l === 'g') {
			return ((int) substr($value, 0, -1) * 1024 * 1024 * 1024);
		}

		return (int) $value;
	}

}
