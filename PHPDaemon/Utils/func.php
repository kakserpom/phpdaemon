<?php

if (function_exists('mb_substr') && ini_get('mbstring.func_overload') & 2) {
	/**
	 * @param string $s
	 * @param int $p
	 * @param int|null $l
	 * @return string
	 */
	function binarySubstr($s, $p, $l = 0xFFFFFFF) {
		return mb_substr($s, $p, $l, 'ASCII');
	}
} else {
	/**
	 * @param string $s
	 * @param int $p
	 * @param int|null $l
	 * @return string
	 */
	function binarySubstr($s, $p, $l = null) {
		if ($l === null) {
			$ret = substr($s, $p);
		}
		else {
			$ret = substr($s, $p, $l);
		}

		if ($ret === false) {
			$ret = '';
		}

		return $ret;
	}
}

if (!function_exists('D')) {
	function D() {
		\PHPDaemon\Core\Daemon::log(\PHPDaemon\Core\Debug::dump(...func_get_args()));
		//\PHPDaemon\Core\Daemon::log(\PHPDaemon\Core\Debug::backtrace());
	}
}
if (!function_exists('igbinary_serialize')) {
	function igbinary_serialize($m) {
		return serialize($m);
	}

	function igbinary_unserialize($m) {
		return unserialize($m);
	}
}
if (!function_exists('setTimeout')) {
	function setTimeout($cb, $timeout = null, $id = null, $priority = null) {
		return \PHPDaemon\Core\Timer::add($cb, $timeout, $id, $priority);
	}
}
if (!function_exists('clearTimeout')) {
	function clearTimeout($id) {
		\PHPDaemon\Core\Timer::remove($id);
	}
}
