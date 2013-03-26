<?php
/**
 * Binary substring function
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

// @todo move to some class

if (ini_get('mbstring.func_overload') & 2) {
	function binarySubstr($s, $p, $l = 0xFFFFFFF) {
		return substr($s, $p, $l, 'ASCII');
	}
} else {
	function binarySubstr($s, $p, $l = NULL) {
		if ($l === NULL) {
			$ret = substr($s, $p);
		} else {
			$ret = substr($s, $p, $l);
		}

		if ($ret === FALSE) {
			$ret = '';
		}

		return $ret;
	}
}
