<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Debug
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description Implementation of the master thread.
/**************************************************************************/

class Debug {

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
