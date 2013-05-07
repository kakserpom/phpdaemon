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
		if ('Pool' === $class) {
			return '\\PHPDaemon\\Core\\Pool';
		}
		if ('Example' === substr($class, 0, 7)) {
			array_unshift($e, 'Examples');
		}
		if ('Server' === substr($class, -6)) {
			$path = '\\PHPDaemon\\Servers\\'.substr($class, 0, -6).'\\Pool';
			return str_replace('\\Servers\\Servers', '\\Servers', $path);
		}
		if ('Client' === substr($class, -6)) {
			$path = '\\PHPDaemon\\Clients\\'.substr($class, 0, -6).'\\Pool';
			return str_replace('\\Clients\\Clients', '\\Clients', $path);
		}
		if ('ClientAsync' === substr($class, -11)) {
			$path = '\\PHPDaemon\\Clients\\'.substr($class, 0, -11).'\\Pool';
			return str_replace('\\Client\\Clients', '\\Clients', $path);
		}
		array_unshift($e, '\\' . Daemon::$config->defaultns->value);
		return implode('\\', $e);
	}

}
