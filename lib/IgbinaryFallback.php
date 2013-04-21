<?php

/**
 * Igbinary fallback
 *
 * @package Core
 *
 * @author Zorin Vasily <maintainer@daemon.io>
 */

if (!function_exists('igbinary_serialize')) {
	function igbinary_serialize($m) {
		return serialize($m);
	}
	function igbinary_unserialize($m) {
		return unserialize($m);
	}
}
