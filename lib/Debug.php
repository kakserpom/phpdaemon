<?php

/**
 * Debug static functions
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Debug {

	/**
	 * Export binary data
	 * @param string String
	 * @param boolean Whether to replace all of chars with escaped sequences
	 * @return string - Escaped string
	 */
	public static function exportBytes($str, $all = FALSE) {
		return preg_replace_callback(
			'~' . ($all ? '.' : '[^A-Za-z\d\.\{\}$<>:;\-_/\\\\]') . '~s',
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
	 * Wrapper of var_dump
	 * @return string Result of var_dump()
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
	 * Returns textual backtrace.
	 * @return string
	 */
	public static function backtrace() {
		if (Daemon::$obInStack) {
			try {
				throw new Exception;
			} catch (Exception $e) {
				return $e->getTraceAsString();
			}
		}
		ob_start();
		debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		$dump = ob_get_contents();
		ob_end_clean();

		return $dump;
	}

}
