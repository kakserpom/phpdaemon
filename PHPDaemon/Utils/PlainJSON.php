<?php
namespace PHPDaemon\Utils;
use PHPDaemon\Core\Daemon;

/**
 * PlainJSON
 * @package   PHPDaemon\Utils
 * @author    Vasily Zorin <maintainer@daemon.io>
 * @author    Efimenko Dmitriy <ezheg89@gmail.com>
 *
 * Use:
 *  $obj1 = new PlainJSON('{"name":"John"}');
 *  $obj2 = new PlainJSON('{"name":"Smit"}');
 *  echo PlainJSON::apply(json_encode([ ['name' => 'Mary' ], $obj1, $obj2]));
 *  // [{"name":"Mary"},{"name":"John"},{"name":"Smit"}]
 */
class PlainJSON implements \JsonSerializable {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	protected $id;
	
	static protected $tr = [];

	/**
	 * Save
	 * @param string $str JSON string
	 */
	public function __construct($str) {
		$this->id = Daemon::uniqid();
		static::$tr['"' . $this->id . '"'] = $str;
	}

	/**
	 * Clean cache
	 */
	public function __destruct() {
		unset(static::$tr[$this->id]);
	}

	/**
	 * jsonSerialize
	 * @return string
	 */
	public function jsonSerialize() {
		return $this->id;
	}

	/**
	 * Apply
	 * @param  string $data JSON string
	 * @return string       Result JSON
	 */
	public static function apply($data) {
		return strtr($data, static::$tr);
	}
}
