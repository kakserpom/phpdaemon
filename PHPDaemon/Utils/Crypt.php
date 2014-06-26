<?php
namespace PHPDaemon\Utils;

/**
 * Class Crypt
 * @package PHPDaemon\Utils
 */
class Crypt {
	use \PHPDaemon\Traits\ClassWatchdog;
	/**
	 * Generate keccak hash for string with salt
	 * @param string $str
	 * @param string $salt
	 * @param boolean $plain = false
	 * @return string
	 */
	public static function hash($str, $salt = '', $plain = false) {
		$size = 512;
		$rounds = 1;
		if (strncmp($salt, '$', 1) === 0) {
			$e = explode('$', $salt, 3);
			$ee = explode('=', $e[1]);
			if (ctype_digit($ee[0])) {
				$size = (int) $e[1];
			}
			if (isset($ee[1]) && ctype_digit($e[1])) {
				$size = (int)$e[1];
			}
		}
		$hash = $str . $salt;
		if ($rounds < 1) {
			$rounds = 1;
		}
		elseif ($rounds > 128) {
			$rounds = 128;
		}
		for ($i = 0; $i < $rounds; ++$i) {
			$hash = keccak_hash($hash, $size);
		}
		if ($plain) {
            return $hash;
        }
		return base64_encode($hash);
	}

	public static function randomString($len = 64, $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-.') {
		$r = '';
		$m = strlen($chars) - 1;
		if ($m < 1) {
			// Invalid parameter for $chars
			return '';
		}
		while (strlen($r) < $len) {
			$c = self::randomBytes(1);
			if(strpos($chars, $c) !== false) {
				$r .= $c;
			}
		}
		return substr($r, 0, $len);
	}

	public static function randomBytes($bytes = 64) {
		$buf = '';
		// http://sockpuppet.org/blog/2014/02/25/safely-generate-random-numbers/
		// Use /dev/urandom over all other methods
		if (is_readable('/dev/urandom')) {
			$fp = fopen('/dev/urandom', 'rb');
			if ($fp !== false) {
				$buf = fread($fp, $bytes);
				fclose($fp);
				if ($buf !== FALSE) {
					return $buf;
				}
			}
		}
		if (function_exists('mcrypt_create_iv')) {
			$buf = mcrypt_create_iv($bytes, MCRYPT_DEV_URANDOM);
			if($buf !== FALSE) {
				return $buf;
			}
		}
		if (function_exists('openssl_random_pseudo_bytes')) {
			$strong = false;
			$buf = openssl_random_pseudo_bytes($bytes, $strong);
			if ($strong) {
				return $buf;
			}
		}
		die("No suitable random number generator exists!");
	}

	/**
	 * Compare strings
	 * @param string $a
	 * @param string $b
	 * @return boolean Equal?
	 */
	public static function compareStrings($a, $b) {
		$al = strlen($a);
		$bl = strlen($b);
    	if ($al !== $bl) {
	        return false;
    	}
    	$d = 0;
    	for ($i = 0; $i < $al; ++$i) {
        	$d |= ord($a[$i]) ^ ord($b[$i]);
    	}
    	return $d === 0;
    }
}

