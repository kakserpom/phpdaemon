<?php
namespace PHPDaemon\Utils;

/**
 * Class Crypt
 * @package PHPDaemon\Utils
 */
class Crypt {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;
	/**
	 * Generate keccak hash for string with salt
	 * @param string $str
	 * @param string $salt
	 * @return string
	 */
	public static function hash($str, $salt = '') {
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
		return base64_encode($hash);
	}

	public static function randomString($len = 64, $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-.:') {
		$r = '';
		$m = strlen($chars) - 1;
		for ($i = 0; $i < $len; ++$i) {
			$r .= $chars[mt_rand(0, $m)];
		}
		return $r;
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

