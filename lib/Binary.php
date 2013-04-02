<?php
class Binary {
	/**
	 * Build structure of labels
	 * @param string Dot-separated labels list
	 * @return binary
	 */
	public static function labels($q) {
 		$e = explode('.', $q);
	 	$r = '';
 		for ($i = 0, $s = sizeof($e); $i < $s; ++$i) {
 			$r .= chr(strlen($e[$i])) . $e[$i];
 		}
 		if (binarySubstr($r, -1) !== "\x00") {
 			$r .= "\x00";
 		}
 		return $r;
	}

	/**
	 * Parse structure of labels
	 * @param binary
	 * @return string Dot-separated labels list
	 */
	public static function parseLabels(&$data, $orig = null) {
		$str = '';
		while (strlen($data) > 0) {
			$l = ord($data[0]);

			if ($l >= 192) {
				$pos = Binary::bytes2int(chr($l - 192) . binarySubstr($data, 1, 1));
				$data = binarySubstr($data, 2);
				$ref = binarySubstr($orig, $pos);
				return $str . Binary::parseLabels($ref);
			}

     		$p = substr($data, 1, $l);
     		$str .= $p.(($l !== 0)?'.':'');
     		$data = substr($data, $l + 1);
       		if ($l === 0) {
     			break;
     		}
    	}
    	return $str;
	}

	/**
	 * Build length-value binary snippet
	 * @param string Data
	 * @param [string Number of bytes to encode length. Default is 1
	 * @return binary
	 */
	public static function LV($str, $len = 1, $lrev = FALSE) {
		$l = static::i2b($len, strlen($str));
		if ($lrev) {
			$l = strrev($l);
		}
		return $l . $str;
	}

	/**
	 * Build nul-terminated string, with 2-byte of length
	 * @param string Data
	 * @return binary
	 */
	public static function LVnull($str) {
		return static::LV($str."\x00", 2, true);
	}

	/**
	 * Build byte
	 * @param integer Byte number
	 * @return binary
	 */
	public static function byte($int) {
		return chr($int);
	}

	/**
	 * Build word (2 bytes) big-endian
	 * @param integer Integer
	 * @return binary
	 */
	public static function word($int) {
		return static::i2b(2, $int);
	}

	/**
	 * Build word (2 bytes) little-endian
	 * @param integer Integer
	 * @return binary
	 */
	public static function wordl($int) {
		return strrev(static::word($int));
	}

	/**
	 * Build double word (4 bytes) big-endian
	 * @param integer Integer
	 * @return binary
	 */
	public static function dword($int) {
		return static::i2b(4,$int);
	}

	/**
	 * Build double word (4 bytes) little endian
	 * @param integer Integer
	 * @return binary
	 */
	public static function dwordl($int) {
		return strrev(static::dword($int));
	}

	/**
	 * Build quadro word (8 bytes) big endian
	 * @param integer Integer
	 * @return binary
	 */
	public static function qword($int) {
		return static::i2b(8, $int);
	}

	/**
	 * Build quadro word (8 bytes) little endian
	 * @param integer Integer
	 * @return binary
	 */
	public static function qwordl($int) {
		return strrev(static::qword($int));
	}

	/**
	 * Parse byte, and remove it
	 * @param &string Data
	 * @return integer
	 */
	public static function getByte(&$p) {
		$r = static::bytes2int($p{0});
		$p = binarySubstr($p, 1);
		return (int) $r;
	}

	/**
	 * Get single-byte character
	 * @param &string Data
	 * @return string
	 */
	public static function getChar(&$p) {
		$r = $p{0};
		$p = binarySubstr($p, 1);
		return $r;
	}

	/**
	 * Parse word (2 bytes)
	 * @param &string Data
	 * @param boolean Little endian? 
	 * @return integer
	 */	
	public static function getWord(&$p, $l = false) {
		$r = static::bytes2int(binarySubstr($p, 0, 2), !!$l);
		$p = binarySubstr($p,2);
		return intval($r);
	}

	/**
	 * Get word (2 bytes)
	 * @param &string Data
	 * @param boolean Little endian? 
	 * @return binary
	 */	
	public static function getStrWord(&$p, $l = false) {
		$r = binarySubstr($p, 0, 2);
		$p = binarySubstr($p, 2);
		if ($l) {
			$r = strrev($r);
		}
		return $r;
	}


	/**
	 * Get double word (4 bytes)
	 * @param &string Data
	 * @param boolean Little endian? 
	 * @return integer
	 */	
	public static function getDWord(&$p,$l = false) {
		$r = static::bytes2int(binarySubstr($p,0,4),!!$l);
		$p = binarySubstr($p,4);
		return intval($r);
	}

	/**
	 * Parse quadro word (8 bytes)
	 * @param &string Data
	 * @param boolean Little endian? 
	 * @return integer
	 */	
	public static function getQword(&$p, $l = false) {
		$r = static::bytes2int(binarySubstr($p,0,8),!!$l);
		$p = binarySubstr($p,8);
		return intval($r);
	}

	/**
	 * Get quadro word (8 bytes)
	 * @param &string Data
	 * @param boolean Little endian? 
	 * @return binary
	 */	
	public static function getStrQWord(&$p, $l = false) {
		$r = binarySubstr($p, 0, 8);
		if ($l) {
			$r = strrev($r);
		}
		$p = binarySubstr($p, 8);
		return $r;
	}

	/**
	 * Parse nul-terminated string
	 * @param &string Data
	 * @return binary
	 */	
	public static function getString(&$str) {
		$p = strpos($str, "\x00");
		if ($p === false) {
			return '';
		}
 		$r = binarySubstr($str, 0, $p);
 		$str = binarySubstr($str, $p+1);
 		return $r;
	}

	/**
	 * Parse length-value structure
	 * @param &string Data
	 * @param number Number of length bytes
	 * @param boolean Nul-terminated? Default is false
	 * @param boolean Length is little endian?
	 * @return string
	 */
	public static function getLV(&$p, $l = 1, $nul = false, $lrev = false) {
 		$s = static::b2i(binarySubstr($p,0,$l),!!$lrev);
 		$p = binarySubstr($p,$l);
		if ($s == 0) {
			return '';
		}
		$r = '';
 		if (strlen($p) < $s) {
 			echo("getLV error: buf length (".strlen($p)."): ".Debug::exportBytes($p).", must be >= string length (".$s.")\n");
 		}
 		elseif ($nul) {
  			if ($p{$s-1} != "\x00") {
  				echo("getLV error: Wrong end of NUL-string (".Debug::exportBytes($p{$s-1})."), len ".$s."\n");
  			}
  			else {
  				$d = $s-1;
  				if ($d < 0) {
  					$d = 0;
  				}
  				$r = binarySubstr($p, 0, $d);
  				$p = binarySubstr($p, $s);
  			}
 		}
 		else {
 			$r = binarySubstr($p, 0, $s);
 			$p = binarySubstr($p, $s);
 		}
 		return $r;
	}


		/**
	 * Converts integer to binary string
	 * @param integer Length
	 * @param integer Integer
	 * @param boolean Optional. Little endian. Default value - false.
	 * @return string Resulting binary string
	 */
	public static function int2bytes($len, $int = 0, $l = false) {
		$hexstr = dechex($int);

		if ($len === NULL) {
			if (strlen($hexstr) % 2) {
				$hexstr = "0".$hexstr;
			}
		} else {
			$hexstr = str_repeat('0', $len * 2 - strlen($hexstr)) . $hexstr;
		}
		
		$bytes = strlen($hexstr) / 2;
		$bin = '';
		
		for($i = 0; $i < $bytes; ++$i) {
			$bin .= chr(hexdec(substr($hexstr, $i * 2, 2)));
		}
		
		return $l ? strrev($bin) : $bin;
	}
	

	/**
	 * Convert array of flags into bit array
	 * @param array Flags
	 * @param integer Length. Default is 4
	 * @return string
	 */
	public static function flags2bitarray($flags, $len = 4) {
		$ret = 0;
		foreach($flags as $v) {
			$ret |= $v;
		}
		return static::i2b($len,$ret);
	}

	/**
	 * @alias int2bytes
	 */
	public static function i2b($bytes, $int = 0, $l = false) {
		return static::int2bytes($bytes, $val, $l);
	}

	/**
	 * Convert bytes into integer
	 * @param string Bytes
	 * @param boolean Little endian? Default is false
	 * @return integer
	 */
	public static function bytes2int($str, $l = false) {
 		if ($l) {
 			$str = strrev($str);
 		}
 		$dec = 0;
 		$len = strlen($str);
 		for($i = 0; $i < $len; ++$i) {
 			$dec += ord(binarySubstr($str, $i, 1)) * pow(0x100, $len - $i - 1);
 		}
 		return $dec;
	}
	
	/**
	 * @alias bytes2int
	 */
	public static function b2i($hex = 0, $l = false) {
		return static::bytes2int($hex, $l);
	}


	/**
	 * Convert bitmap into bytes
	 * @param string Bitmap
	 * @param boolean Check length?
	 * @return binary
	 */
	public static function bitmap2bytes($bitmap, $check_len = 0) {
 		$r = '';
 		$bitmap = str_pad($bitmap, ceil(strlen($bitmap) / 8) * 8,'0',STR_PAD_LEFT);
 		for ($i = 0, $n = strlen($bitmap) / 8; $i < $n; ++$i) {
  			$r .= chr((int) bindec(binarySubstr($bitmap, $i * 8, 8)));
 		}
 		if ($check_len && (strlen($r) != $check_len)) {
 			echo "Warning! Bitmap incorrect.\n";
 		}
 		return $r;
	}

	/**
	 * Get bitmap
	 * @param byte
	 * @return string
	 */
	public static function getbitmap($byte) {
 		return sprintf('%08b',$byte);
	}
}
