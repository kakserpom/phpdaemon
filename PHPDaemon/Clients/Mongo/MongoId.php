<?php
namespace PHPDaemon\Clients\Mongo;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/**
 * @package    Applications
 * @subpackage MongoClientAsync
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class MongoId extends \MongoId {

	/**
	 * Import
	 * @param  mixed $id ID
	 * @return mixed
	 */
	public static function import($id) {
		if ($id instanceof static) {
			return $id;
		}
		elseif ($id instanceof \MongoId) {
			$id = (string) $id;
		}
		elseif (!is_string($id)) {
			if (is_array($id) && isset($id['$id'])) {
				return static::import($id['$id']);
			}
			return false;
		}
		elseif (strlen($id) === 24) {
			 if (!ctype_xdigit($id)) {
				return false;
			}
		} elseif (ctype_alnum($id)) {
			$id = gmp_strval(gmp_init(strrev($id), 62), 16);
			if (strlen($id) > 24) {
				return false;
			}
			if (strlen($id) < 24) {
				$id = str_pad($id, 24, '0', STR_PAD_LEFT);
			}
		} else {
			return false;
		}
		return new static($id);
	}

	/**
	 * @param string $id
	 */
	public function __construct($id = null) {
		if ($id !== null && strlen($id) < 20 && ctype_alnum($id)) {
			$id = gmp_strval(gmp_init(strrev($id), 62), 16);
			if (strlen($id) > 24) {
				$id = 'FFFFFFFFFFFFFFFFFFFFFFFF';
			} elseif (strlen($id) < 24) {
				$id = str_pad($id, 24, '0', STR_PAD_LEFT);
			}
		}
		@parent::__construct($id);
	}

	/**
	 * __toString
	 * @return string
	 */
	public function __toString() {
		return strrev(gmp_strval(gmp_init(parent::__toString(), 16), 62));
	}

	/**
	 * toHex
	 * @return string
	 */
	public function toHex() {
		return parent::__toString();
	}

	/**
	 * getPlainObject
	 * @return \MongoId
	 */
	public function getPlainObject() {
		return new \MongoId(parent::__toString());
	}
}
