<?php
if (ini_get('mbstring.func_overload') & 2) {
	function binarySubstr($s, $p, $l = 0xFFFFFFF) {
		return substr($s, $p, $l, 'ASCII');
	}
}
else
if (!function_exists('binarySubstr')) {
	function binarySubstr($s, $p, $l = NULL) {
		if ($l === NULL) {
			$ret = substr($s, $p);
		}
		else {
			$ret = substr($s, $p, $l);
		}

		if ($ret === FALSE) {
			$ret = '';
		}
		return $ret;
	}
}
if (!function_exists('D')) {
	function D() {
		\PHPDaemon\Core\Daemon::log(call_user_func_array('\PHPDaemon\Core\Debug::dump', func_get_args()));
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
