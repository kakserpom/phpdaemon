<?php

/**
 * Boolean config entry
 *
 * @package Core
 * @subpackage Config
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon_ConfigEntryBoolean extends Daemon_ConfigEntry {

	public static function HumanToPlain($value) {
		return (boolean) $value;
	}

}
