<?php
namespace PHPDaemon\Core;

/**
 * Class finder
 *
 * @package Core
 *
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class ClassFinder {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * Get base class name of the given class or object
	 * @param string|object $class Object or String
	 * @return string Class
	 */
	public static function getClassBasename($class) {
		if (is_object($class)) {
			$class = get_class($class);
		}
		$e = explode('\\', $class);
		return end($e);
	}

	/**
	 * Find class
	 * @param string $class Class
	 * @param string $namespace Namespace
	 * @param string $rootns Root namespace
	 * @return string
	 */
	public static function find($class, $namespace = null, $rootns = null) {
		$e = explode('\\', $class);
		if ($e[0] === '') {
			return $class;
		}
		if ('Pool' === $class || 'TransportContext' === $class) {
			return '\\PHPDaemon\\Core\\' . $class;
		}
		if (strpos($class, '\\') === false && $namespace === null) {
			if ('Example' === substr($class, 0, 7)) {
				array_unshift($e, 'Examples');
			}
			if ('Server' === substr($class, -6)) {
				$path = '\\PHPDaemon\\Servers\\' . substr($class, 0, -6) . '\\Pool';
				$r    = str_replace('\\Servers\\Servers', '\\Servers', $path);
				Daemon::log('ClassFinder: \'' . $class . '\' -> \'' . $r . '\', you should change your code.');
				return $r;
			}
			if ('Client' === substr($class, -6)) {
				$path = '\\PHPDaemon\\Clients\\' . substr($class, 0, -6) . '\\Pool';
				$r    = str_replace('\\Clients\\Clients', '\\Clients', $path);
				Daemon::log('ClassFinder: \'' . $class . '\' -> \'' . $r . '\', you should change your code.');
				return $r;
			}
			if ('ClientAsync' === substr($class, -11)) {
				$path = '\\PHPDaemon\\Clients\\' . substr($class, 0, -11) . '\\Pool';
				$r    = str_replace('\\Client\\Clients', '\\Clients', $path);
				Daemon::log('ClassFinder: \'' . $class . '\' -> \'' . $r . '\', you should change your code.');
				return $r;
			}
		}
		if ($namespace !== null && sizeof($e) < 2) {
			array_unshift($e, $namespace);
		}
		array_unshift($e, '\\' . ($rootns !== null ? $rootns : Daemon::$config->defaultns->value));
		return implode('\\', $e);
	}

}
