<?php

/**
 * Time config entry
 *
 * @package Core
 * @subpackage Config
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon_ConfigEntryTime extends Daemon_ConfigEntry {

	public static function HumanToPlain($value) {
		$time = 0;

		preg_replace_callback('~(\d+(\.\d+)?)\s*([smhd])\s*|(.+)~i', function($m) use (&$time) {
			if (isset($m[4]) && ($m[4] !== '')) {
				$time = false;
			}

			if ($time === false) {
				return;
			}

			if (!empty($m[2]))  {
				$n = (float) $m[1];
			} else {
				$n = (int) $m[1];
			}

			$l = strtolower($m[3]);

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
