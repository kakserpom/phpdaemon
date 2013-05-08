<?php
namespace PHPDaemon\Core;

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
	 *
	 * @param string Class
	 * @param string Namespace
	 * @return string Class
	 */
	public static function find($class, $namespace = null) {
		$e = explode('\\', $class);
		if ($e[0] === '') {
			return $class;
		}
		if ('Pool' === $class || 'TransportContext' === $class) {
			return '\\PHPDaemon\\Core\\' . $class;
		}
		if ('Example' === substr($class, 0, 7)) {
			array_unshift($e, 'Examples');
		}
		if ('Server' === substr($class, -6)) {
			$path = '\\PHPDaemon\\Servers\\' . substr($class, 0, -6) . '\\Pool';
			return str_replace('\\Servers\\Servers', '\\Servers', $path);
		}
		if ('Client' === substr($class, -6)) {
			$path = '\\PHPDaemon\\Clients\\' . substr($class, 0, -6) . '\\Pool';
			return str_replace('\\Clients\\Clients', '\\Clients', $path);
		}
		if ('ClientAsync' === substr($class, -11)) {
			$path = '\\PHPDaemon\\Clients\\' . substr($class, 0, -11) . '\\Pool';
			return str_replace('\\Client\\Clients', '\\Clients', $path);
		}
		if ($namespace !== null) {
			array_unshift($e, $namespace);
		}
		array_unshift($e, '\\' . Daemon::$config->defaultns->value);
		return implode('\\', $e);
	}

}
