<?php

/**
 * Number config entry
 *
 * @package Core
 * @subpackage Config
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon_ConfigEntryNumber extends Daemon_ConfigEntry {

	public function HumanToPlain($value) {
		if ($value === null) {
			return null;
		}
	 	$l = substr($value, -1);

		if (
			($l === 'k') 
			|| ($l === 'K')
		) {
			return ((int) substr($value, 0, -1) * 1000);
		}

		if (
			($l === 'm') 
			|| ($l === 'M')
		) {
			return ((int) substr($value, 0, -1) * 1000 * 1000);
		}

		if (
			($l === 'G') 
			|| ($l === 'G')
		) {
			return ((int) substr($value, 0, -1) * 1000 * 1000 * 1000);
		}
		return (int) $value;
	}

}
