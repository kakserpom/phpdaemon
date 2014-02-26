<?php
namespace PHPDaemon\Clients\Mongo;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

class MongoId extends \MongoId {
	public static function import($id) {
		if ($id instanceof static) {
			return $id;
		}
		elseif ($id instanceof \MongoId) {
			$id = (string) $id;
		}
		elseif (!is_string($id)) {
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
	public function __construct($id = null) {
		if ($id !== null && strlen($id) < 20 && ctype_alnum($id)) {
			$id = gmp_strval(gmp_init(strrev($id), 62), 16);
			if (strlen($id) > 24) {
				$id = 'FFFFFFFFFFFFFFFFFFFFFFFF';
			} elseif (strlen($id) < 24) {
				$id = str_pad($id, 24, '0', STR_PAD_LEFT);
			}
		}
		parent::__construct($id);
	}
	public function __toString() {
		return strrev(gmp_strval(gmp_init(parent::__toString(), 16), 62));
	}
	public function toHex() {
		return parent::__toString();
	}

	public function getPlainObject() {
		return new \MongoId(parent::__toString());
	}
}
