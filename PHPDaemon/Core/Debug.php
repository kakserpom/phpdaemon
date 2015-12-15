<?php
namespace PHPDaemon\Core;

use PHPDaemon\Core\Daemon;

/**
 * Debug static functions
 * @package PHPDaemon\Core
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class Debug {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * Export binary data
	 * @param  string  $str String
	 * @param  boolean $all Whether to replace all of chars with escaped sequences
	 * @return string Escaped string
	 */
	public static function exportBytes($str, $all = FALSE) {
		return preg_replace_callback(
			'~' . ($all ? '.' : '[^A-Za-z\d\.\{\}$<>:;\-_/\\\\=+]') . '~s',
			function ($m) use ($all) {
				if (!$all) {
					if ($m[0] === "\r") {
						return "\n" . '\r';
					}

					if ($m[0] === "\n") {
						return '\n';
					}
				}

				return sprintf('\x%02x', ord($m[0]));
			}, $str);
	}

	/**
	 * Returns pretty-printed JSON
	 * @param  mixed  $m Data
	 * @return string
	 */
	public static function prettyJson($m) {
		return json_encode($m, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Returns JSON
	 * @param  mixed  $m Data
	 * @return string
	 */
	public static function json($m) {
		return json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * Returns a proxy callback function with logging for debugging purposes
	 * @param  callable $cb   Callback
	 * @param  mixed    $name Data
	 * @return callable
	 */
	public static function proxy($cb, $name = null) {
		static $i = 0;
		$n = ++$i;
		Daemon::log('Debug::proxy #'.$n.': SPAWNED ('.json_encode($name). ')');
		return function() use ($cb, $name, $n) {
			Daemon::log('Debug::proxy #'.$n.': CALLED ('.json_encode($name). ')');
			call_user_func_array($cb, func_get_args());
		};

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
	 * Get refcount of the given variable
	 * @param  mixed &$var Value
	 * @return integer
	 */
	public static function refcount(&$var) {
		ob_start();
		debug_zval_dump([&$var]);
		$c = preg_replace("/^.+?refcount\((\d+)\).+$/ms", '$1', substr(ob_get_contents(), 24), 1) - 4;
		ob_end_clean();
		return $c;
	}

	/**
	 * Wrapper of debug_zval_dump
	 * @return string Result of debug_zval_dump()
	 */
	public static function zdump() {
		ob_start();

		foreach (func_get_args() as $v) {
			debug_zval_dump($v);
		}

		$dump = ob_get_contents();
		ob_end_clean();

		return $dump;
	}

	/**
	 * Returns textual backtrace
	 * @return string
	 */
	public static function backtrace($bool = false) {
		if (Daemon::$obInStack || $bool) {
			try {
				throw new \Exception;
			} catch (\Exception $e) {
				$trace = $e->getTraceAsString();
				$e     = explode("\n", $trace);
				array_shift($e);
				array_shift($e);
				return implode("\n", $e);
			}
		}
		ob_start();
		debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		$trace = ob_get_contents();
		ob_end_clean();

		$e = explode("\n", $trace);
		array_shift($e);
		return implode("\n", $e);
	}

}
