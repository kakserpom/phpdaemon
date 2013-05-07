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
	 * @param string Classname
	 * @return string Classname
	 */
	public static function find($classname) {
		$e = explode('\\', $classname);
		if ($e[0] === '') {
			return $classname;
		}
		array_unshift($e, '\\' . Daemon::$config->defaultns->value);
		return implode('\\', $e);
	}

}
