<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Debug
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description Debug static functions.
/**************************************************************************/

class Debug {

	/**
	 * @method exportBytes
	 * @param string String.
	 * @param boolean Whether to replace all of chars with escaped sequences.
	 * @description Exports binary data.
	 * @return string - Escaped string.
	 */
	public static function exportBytes($str, $all = FALSE) {
		return preg_replace_callback(
			'~' . ($all ? '.' : '[^A-Za-z\d\.\{\}$:;\-_/\\\\]') . '~s',
			function($m) use ($all) {
				if (!$all) {
					if ($m[0] == "\r") {
						return "\n" . '\r';
					}

					if ($m[0] == "\n") {
						return '\n';
					}
				}

				return sprintf('\x%02x', ord($m[0]));
			}, $str);
	}
	
	/**
	 * @method dump
	 * @description Wrapper of var_dump.
	 * @return string - Result of var_dump().
	 */
	public static function dump() {
		ob_start();

		foreach (func_get_args() as $v) {
			var_dump($v);
		}

		$dump = ob_get_contents();
		ob_end_clean();

		return $dump;
	}
	
	/**
	 * @method backtrace
	 * @description Returns textual backtrace.
	 * @return void
	 */
	public static function backtrace() {
		ob_start();
		debug_print_backtrace();
		$dump = ob_get_contents();
		ob_end_clean();

		return $dump;
	}

}
