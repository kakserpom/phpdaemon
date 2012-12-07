<?php
class Binary {
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
	public static function parseLabels(&$data, $orig = null) {
		$domain = '';
		while (strlen($data) > 0) {
			$l = ord($data[0]);

			if ($l >= 192) {
				$pos = Binary::bytes2int(chr($l - 192) . binarySubstr($data, 1, 1));
				$data = binarySubstr($data, 2);
				$ref = binarySubstr($orig, $pos);
				return $domain . Binary::parseLabels($ref);
			}

     		$p = substr($data, 1, $l);
     		$domain .= $p.(($l !== 0)?'.':'');
     		$data = substr($data, $l + 1);
       		if ($l === 0) {
     			break;
     		}
    	}
    	return $domain;
	}
	public static function LV($string, $len = 1, $lrev = FALSE) {
		$l = self::i2b($len, strlen($string));
		if ($lrev) {
			$l = strrev($l);
		}
		return $l . $string;
	}
	public static function LVnull($string) {
		return self::LV($string."\x00",2,TRUE);
	}
	public static function byte($int) {
		return chr($int);
	}

	public static function word($int) {
		return self::i2b(2, $int);
	}

	public static function wordl($int) {
		return strrev(self::word($int));
	}

	public static function dword($int) {
		return self::i2b(4,$int);
	}

	public static function dwordl($int) {
		return strrev(self::dword($int));
	}

	public static function qword($int) {
		return self::i2b(8, $int);
	}

	public static function qwordl($int) {
		return strrev(self::qword($int));
	}

	public static function getByte(&$p) {
		$r = self::bytes2int($p{0});
		$p = binarySubstr($p, 1);
		return (int) $r;
	}

	public static function getChar(&$p) {
		$r = $p{0};
		$p = binarySubstr($p, 1);
		return $r;
	}
	
	public static function getWord(&$p, $l = false) {
		$r = self::bytes2int(binarySubstr($p,0,2),!!$l);
		$p = binarySubstr($p,2);
		return intval($r);
	}

	public static function getDWord(&$p,$l = false) {
		$r = self::bytes2int(binarySubstr($p,0,4),!!$l);
		$p = binarySubstr($p,4);
		return intval($r);
	}
	public static function getQword(&$p, $l = false) {
		$r = self::bytes2int(binarySubstr($p,0,8),!!$l);
		$p = binarySubstr($p,8);
		return intval($r);
	}
	public static function getStrQWord(&$p,$l = false) {
		$r = binarySubstr($p, 0, 8);
		if ($l) {
			$r = strrev($r);
		}
		$p = binarySubstr($p, 8);
		return $r;
	}
	public static function getString(&$str) {
		$p = strpos($str,"\x00");
		if ($p === FALSE) {
			return '';
		}
 		$r = substr($str,0,$p);
 		$str = substr($str, $p+1);
 		return $r;
	}
	public static function getLV(&$p, $l = 1, $nul = false, $lrev = false) {
 		$s = self::b2i(binarySubstr($p,0,$l),!!$lrev);
 		//Daemon::log('s = '.$s. ' -- '.Debug::exportBytes(binarySubstr($p,0,$l), true));
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

	public static function int2bytes($len,$int = 1) {
 		$hexstr = dechex($int);
 		if ($len === NULL) {
 			if (strlen($hexstr) % 2) {
 				$hexstr = "0".$hexstr;
 			}
 		}
 		else {
 			$hexstr = str_repeat('0', $len * 2 - strlen($hexstr)) . $hexstr;
 		}
 		$bytes = strlen($hexstr)/2;
 		$bin = '';
 		for($i = 0; $i < $bytes; ++$i) {
 			$bin .= chr(hexdec(binarySubstr($hexstr,$i*2,2)));
 		}
 		return $bin;
	}
	
	public static function flags2bitarray($flags, $len = 4) {
		$ret = 0;
		foreach($flags as $v) {
			$ret |= $v;
		}
		return self::i2b($len,$ret);
	}
	public static function i2b($bytes, $val = 0) {
		return self::int2bytes($bytes, $val);
	}
	
	public static function bytes2int($str, $l = false) {
 		if ($l) {
 			$str = strrev($str);
 		}
 		$dec = 0;
 		$len = strlen($str);
 		for($i = 0; $i < $len; ++$i) {
 			$dec += ord(binarySubstr($str,$i,1))*pow(256,$len-$i-1);
 		}
 		return $dec;
	}

	public static function b2i($hex = 0, $l = false) {
		return self::bytes2int($hex, $l);
	}

	public static function bitmap2bytes($bitmap, $check_len = 0) {
 		$r = '';
 		$bitmap = str_pad($bitmap,ceil(strlen($bitmap)/8)*8,'0',STR_PAD_LEFT);
 		for ($i = 0, $n = strlen($bitmap)/8; $i < $n; ++$i) {
  			$r .= chr((int) bindec(binarySubstr($bitmap,$i*8,8)));
 		}
 		if ($check_len && (strlen($r) != $check_len)) {
 			echo "Warning! Bitmap incorrect.\n";
 		}
 		return $r;
	}
	public static function getbitmap($byte) {
 		return sprintf('%08b',$byte);
	}
}