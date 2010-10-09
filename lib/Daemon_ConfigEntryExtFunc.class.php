<?php

/**
 * External function config entry
 *
 * @package Core
 * @subpackage Config
 * @author kak.serpom.po.yaitsam@gmail.com
 */

class Daemon_ConfigEntryExtFunc extends Daemon_ConfigEntry {

	public function HumanToPlain($value) {
		$cb = include($value);

		return is_callable($cb) ? $cb : NULL;
	}

}
