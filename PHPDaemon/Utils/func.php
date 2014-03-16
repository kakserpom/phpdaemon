<?php
if (ini_get('mbstring.func_overload') & 2) {
	function binarySubstr($s, $p, $l = 0xFFFFFFF) {
		return substr($s, $p, $l, 'ASCII');
	}
}
else {
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
if (!function_exists('igbinary_serialize')) {
	function igbinary_serialize($m) {
		return serialize($m);
	}

	function igbinary_unserialize($m) {
		return unserialize($m);
	}
}

function setTimeout($cb, $timeout = null, $id = null, $priority = null) {
	return \PHPDaemon\Core\Timer::add($cb, $timeout, $id, $priority);
}

function clearTimeout($id) {
	\PHPDaemon\Core\Timer::remove($id);
}
