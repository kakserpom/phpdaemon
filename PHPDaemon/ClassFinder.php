<?php
namespace PHPDaemon;

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
		if (substr($class, -6) === 'Server') {
			array_unshift($e, 'Servers');
		}
		if (substr($class, -6) === 'Client') {
			array_unshift($e, 'Clients');
		}
		array_unshift($e, '\\' . Daemon::$config->defaultns->value);
		return implode('\\', $e);
	}

}
