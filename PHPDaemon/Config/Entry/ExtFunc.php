<?php
namespace PHPDaemon\Config\Entry;

use PHPDaemon\Config\Entry\Generic;

/**
 * External function config entry
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class ExtFunc extends Generic {

	/**
	 * @param $value
	 * @return callable|null
	 */
	public static function HumanToPlain($value) {
		$cb = include($value);
		return is_callable($cb) ? $cb : null;
	}

}
