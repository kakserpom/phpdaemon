<?php
namespace PHPDaemon\Utils;
use PHPDaemon\FS\FileSystem;

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

	public static function randomString($len = 64, $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-.', $cb = null, $pri = 0, $hang = false) {
		if ($cb === null) {
			Daemon::log('[CODE WARN] \\PHPDaemon\\Utils\\Crypt::randomString: non-callback way is not secure.'
					.' Please rewrite your code with callback function in third argument' . PHP_EOL . Debug::backtrace());

			$r = '';
			$m = strlen($chars) - 1;
			for ($i = 0; $i < $len; ++$i) {
				$r .= $chars[mt_rand(0, $m)];
			}
			return $r;
		}
		static::randomBytes($len, function($bytes) use ($cb, $chars) {
			if ($bytes === false) {
				call_user_func($cb, false);
				return;
			}
			$len = strlen($bytes);
			$m = strlen($chars) - 1;
			$r = '';
			for ($i = 0; $i < $len; ++$i) {
				$r .= $chars[ord($bytes[$i]) % $m]; 
			}
			call_user_func($cb, $r);
		}, $pri, $hang);
	}

	public static function randomBytes($len, $cb, $pri = 0, $hang = false) {
		FileSystem::open('/dev/' . ($hang ? '' : 'u') . 'random', 'r', function ($file) use ($len, $cb, $pri) {
			if (!$file) {
				call_user_func($cb, false);
			}
			$file->read($len, 0, function($file, $data) use ($cb) {
				call_user_func($cb, $data);
			}, $pri);
		}, null, $pri);
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

