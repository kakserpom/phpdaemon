<?php

/**
 * Time config entry
 *
 * @package Core
 * @subpackage Config
 * @author kak.serpom.po.yaitsam@gmail.com
 */

class Daemon_ConfigEntryTime extends Daemon_ConfigEntry {

	public function HumanToPlain($value) {
		$time = 0;

		preg_replace_callback('~(\d+)\s*([smhd])\s*|(.+)~i', function($m) use (&$time) {
			if (
				isset($m[3]) 
				&& ($m[3] !== '')
			) {
				$time = FALSE;
			}

			if ($time === FALSE) {
				return;
			}

			$n = (int) $m[1];
			$l = strtolower($m[2]);

			if ($l === 's') {
				$time += $n;
			}
			elseif ($l === 'm') {
				$time += $n * 60;
			}
			elseif ($l === 'h') {
				$time += $n * 60 * 60;
			}
			elseif ($l === 'd') {
				$time += $n * 60 * 60 * 24;
			}
		}, $value);

		return $time;
	}
}
