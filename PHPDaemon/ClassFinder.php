<?php
namespace PHPDaemon;

use PHPDaemon\Core\Daemon;

/**
 * Class finder
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class ClassFinder {

	/**
	 *
	 * @param string Class
	 * @return string Class
	 */
	public static function find($class) {
		$e = explode('\\', $class);
		if ($e[0] === '') {
			return $class;
		}
		if ('Example' === substr($class, 0, 7)) {
			array_unshift($e, 'Examples');
		}
		if ('Server' === substr($class, -6)) {
			array_unshift($e, 'Servers');
		}
		if ('Client' === substr($class, -6)) {
			array_unshift($e, 'Clients');
		}
		if ('ClientAsync' === substr($class, -11)) {
			array_unshift($e, 'Clients');
		}
		array_unshift($e, '\\' . Daemon::$config->defaultns->value);
		return implode('\\', $e);
	}

}
