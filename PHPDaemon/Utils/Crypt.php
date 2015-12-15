<?php
namespace PHPDaemon\Utils;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\FS\FileSystem;

/**
 * Crypt
 * @package PHPDaemon\Utils
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class Crypt {
	use \PHPDaemon\Traits\ClassWatchdog;
	
	/**
	 * Generate keccak hash for string with salt
	 * @param  string  $str   Data
	 * @param  string  $salt  Salt
	 * @param  boolean $plain Is plain text?
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
			$hash = \keccak_hash($hash, $size);
		}
		if ($plain) {
			return $hash;
		}
		return base64_encode($hash);
	}

	/**
	 * Returns string of pseudo random characters
	 * @param  integer  $len   Length of desired string
	 * @param  string   $chars String of allowed characters
	 * @param  callable $cb    Callback
	 * @param  integer  $pri   Priority of EIO operation
	 * @param  boolean  $hang  If true, we shall use /dev/random instead of /dev/urandom and it may cause a delay
	 * @return string
	 */
	public static function randomString($len = null, $chars = null, $cb = null, $pri = 0, $hang = false) {
		if ($len === null) {
			$len = 64;
		}
		if ($chars === null) {
			$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-.';
		}
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
		$charsLen = strlen($chars);
		$mask = static::getMinimalBitMask($charsLen - 1);
		$iterLimit = max($len, $len * 64);
		static::randomInts(2 * $len, function($ints) use ($cb, $chars, $charsLen, $len, $mask, &$iterLimit) {
			if ($ints === false) {
				call_user_func($cb, false);
				return;
			}
			$r = '';
			for ($i = 0, $s = sizeof($ints); $i < $s; ++$i) {
				// This is wasteful, but RNGs are fast and doing otherwise adds complexity and bias
				$c = $ints[$i] & $mask;
				// Only use the random number if it is in range, otherwise try another (next iteration)
				if ($c < $charsLen) {
					$r .= static::stringIdx($chars, $c);
				}
				// Guarantee termination
				if (--$iterLimit <= 0) {
					return false;
				}
			}
			$d = $len - strlen($r);
			if ($d > 0) {
				static::randomString($d, $chars, function($r2) use ($r, $cb) {
					call_user_func($cb, $r . $r2);
				});
				return;
			}
			call_user_func($cb, $r);
		}, $pri, $hang);
	}

	/**
	 * Returns the character at index $idx in $str in constant time
	 * @param  string  $str String
	 * @param  integer $idx Index
	 * @return string
	 */
	public static function stringIdx($str, $idx) {
		// FIXME: Make the const-time hack below work for all integer sizes, or
		// check it properly
		$l = strlen($str);
		if ($l > 65535 || $idx > $l) {
			return false;
		}
		$r = 0;
		for ($i = 0; $i < $l; ++$i) {
			$x = $i ^ $idx;
			$mask = (((($x | ($x >> 16)) & 0xFFFF) + 0xFFFF) >> 16) - 1;
			$r |= ord($str[$i]) & $mask;
		}
		return chr($r);
	}

	/**
	 * Returns string of pseudo random bytes
	 * @param  integer  $len  Length of desired string
	 * @param  callable $cb   Callback
	 * @param  integer  $pri  Priority of EIO operation
	 * @param  boolean  $hang If true, we shall use /dev/random instead of /dev/urandom and it may cause a delay
	 * @return integer
	 */
	public static function randomBytes($len, $cb, $pri = 0, $hang = false) {
		FileSystem::open('/dev/' . ($hang ? '' : 'u') . 'random', 'r', function ($file) use ($len, $cb, $pri) {
			if (!$file) {
				call_user_func($cb, false);
				return;
			}
			$file->read($len, 0, function($file, $data) use ($cb) {
				call_user_func($cb, $data);
			}, $pri);
		}, null, $pri);
	}

	/**
	 * Returns array of pseudo random integers of machine-dependent size
	 * @param  integer  $numInts Number of integers
	 * @param  callable $cb      Callback
	 * @param  integer  $pri     Priority of EIO operation
	 * @param  boolean  $hang    If true, we shall use /dev/random instead of /dev/urandom and it may cause a delay
	 * @return integer
	 */
	public static function randomInts($numInts, $cb, $pri = 0, $hang = false) {
		static::randomBytes(PHP_INT_SIZE * $numInts, function($bytes) use ($cb, $numInts) {
			if ($bytes === false) {
				call_user_func($cb, false);
				return;
			}
			$ints = [];
			for ($i = 0; $i < $numInts; ++$i) {
				$thisInt = 0;
				for ($j = 0; $j < PHP_INT_SIZE; ++$j) {
					$thisInt = ($thisInt << 8) | (ord($bytes[$i * PHP_INT_SIZE + $j]) & 0xFF);
				}
				// Absolute value in two's compliment (with min int going to zero)
				$thisInt = $thisInt & PHP_INT_MAX;
				$ints[] = $thisInt;
			}
			call_user_func($cb, $ints);
		}, $pri, $hang);
	}

	/**
	 * Returns array of pseudo random 32-bit integers
	 * @param  integer  $numInts Number of integers
	 * @param  callable $cb      Callback
	 * @param  integer  $pri     Priority of EIO operation
	 * @param  boolean  $hang    If true, we shall use /dev/random instead of /dev/urandom and it may cause a delay
	 * @return integer
	 */
	public static function randomInts32($numInts, $cb, $pri = 0, $hang = false) {
		static::randomBytes(4 * $numInts, function($bytes) use ($cb, $numInts) {
			if ($bytes === false) {
				call_user_func($cb, false);
				return;
			}
			$ints = [];
			for ($i = 0; $i < $numInts; ++$i) {
				$thisInt = 0;
				for ($j = 0; $j < 4; ++$j) {
					$thisInt = ($thisInt << 8) | (ord($bytes[$i * 4 + $j]) & 0xFF);
				}
				// Absolute value in two's compliment (with min int going to zero)
				$thisInt = $thisInt & 0xFFFFFFFF;
				$ints[] = $thisInt;
			}
			call_user_func($cb, $ints);
		}, $pri, $hang);
	}

	/**
	 * Returns the smallest bit mask of all 1s such that ($toRepresent & mask) = $toRepresent
	 * @param  integer $toRepresent must be an integer greater than or equal to 1
	 * @return integer
	 */
	protected static function getMinimalBitMask($toRepresent) {
		if ($toRepresent < 1) {
			return false;
		}
		$mask = 0x1;
		while ($mask < $toRepresent) {
			$mask = ($mask << 1) | 1;
		}
		return $mask;
	}

	/**
	 * Compare strings
	 * @param  string  $a String 1
	 * @param  string  $b String 2
	 * @return boolean    Equal?
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
