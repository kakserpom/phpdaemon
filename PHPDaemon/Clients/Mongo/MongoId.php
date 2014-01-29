<?php
namespace PHPDaemon\Clients\Mongo;

class MongoId extends \MongoId {
	protected static $index = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
	public static function fromString($id) {
		if (!is_string($id)) {
			return false;
		}
		if (strlen($id) === 24) {
			 if (!ctype_xdigit($id)) {
				return false;
			}
		} elseif ($id <= 12 && ctype_alnum($id)) {
			return new static(static::dechex(static::StringToNumber($id)));
		} else {
			return false;
		}
		return new static($id);
	}
	public function encode() {
		return static::numberToString(static::hexdec((string) $this));
	}
	public static function dechex($num) {
		static $index = '0123456789abcdef';
	    if ($num <= 0) {
	    	$num = 0;
	    }
	    $base = strlen($index);
	    $res = '';
	    while ($num > 0) {
	        $char = bcmod($num, $base);
	        $res .= substr($index, $char, 1);
	        $num = bcsub($num, $char);
	        $num = bcdiv($num, $base);
	    }
	    return $res;
	}
	public static function hexdec($str) {
		static $index = '0123456789abcdef';
       	$base = strlen($index);
       	$str = strrev($str);
    	$out = '0';
    	$len = strlen( $str ) - 1;
    	for ($t = 0; $t <= $len; ++$t) {
        	$out = bcadd($out, strpos($index, substr( $str, $t, 1 ) ) * pow( $base, $len - $t ));
    	}
    	return $out;
	}
	public static function numberToString($num) {
	    if ($num <= 0) {
	    	$num = 0;
	    }
	    $base = strlen(static::$index);
	    $res = '';
	    while ($num > 0) {
	        $char = bcmod($num, $base);
	        $res .= substr(static::$index, $char, 1);
	        $num = bcsub($num, $char);
	        $num = bcdiv($num, $base);
	    }
	    return $res;
	}

	public static function StringToNumber($str) {
       	$base = strlen(static::$index);
       	$str = strrev($str);
    	$out = '0';
    	$len = strlen( $str ) - 1;
    	for ($t = 0; $t <= $len; ++$t) {
        	$out = bcadd($out, strpos(static::$index, substr( $str, $t, 1 ) ) * pow( $base, $len - $t ));
    	}
    	return $out;
	}
}
