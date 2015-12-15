<?php
namespace PHPDaemon\FS;

use PHPDaemon\Cache\CappedStorage;
use PHPDaemon\Cache\CappedStorageHits;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\CallbackWrapper;

/**
 * FileSystem
 * @package PHPDaemon\FS
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class FileSystem {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var boolean Is EIO supported?
	 */
	public static $supported;

	/**
	 * @var Event Main FS event
	 */
	public static $ev;

	/**
	 * @var resource EIO file descriptor
	 */
	public static $fd;

	/**
	 * @var array Mode types
	 */
	public static $modeTypes = [
		0140000 => 's',
		0120000 => 'l',
		0100000 => 'f',
		0060000 => 'b',
		0040000 => 'd',
		0020000 => 'c',
		0010000 => 'p',
	];

	/**
	 * @var integer TTL for bad descriptors in seconds
	 */
	public static $badFDttl = 5;

	/**
	 * @var PHPDaemon\Cache\CappedStorage File descriptor cache
	 */
	public static $fdCache;

	/**
	 * @var integer Maximum number of open files in cache
	 */
	public static $fdCacheSize = 128;

	/**
	 * @var string Required EIO version
	 */
	public static $eioVer = '1.2.1';

	/**
	 * Initialize FS driver
	 * @return void
	 */
	public static function init() {
		if (!Daemon::$config->eioenabled->value) {
			self::$supported = false;
			return;
		}
		if (!self::$supported = Daemon::loadModuleIfAbsent('eio', self::$eioVer)) {
			Daemon::log('FS: missing pecl-eio >= ' . self::$eioVer . '. Filesystem I/O performance compromised. Consider installing pecl-eio. `pecl install http://pecl.php.net/get/eio`');
			return;
		}
		self::$fdCache = new CappedStorageHits(self::$fdCacheSize);
		eio_init();
	}

	/**
	 * Initialize main FS event
	 * @return void
	 */
	public static function initEvent() {
		if (!self::$supported) {
			return;
		}
		self::updateConfig();
		self::$fd = eio_get_event_stream();
		self::$ev = new \Event(Daemon::$process->eventBase, self::$fd, \Event::READ | \Event::PERSIST, function ($fd, $events, $arg) {
			while (eio_nreqs()) {
				eio_poll();
			}
		});
		self::$ev->add();
	}

	/**
	 * Checks if file exists and readable
	 * @param  string $path Path
	 * @return boolean      Exists and readable?
	 */
	public static function checkFileReadable($path) {
		return is_file($path) && is_readable($path);

	}

	/**
	 * Block until all FS events are completed
	 * @return void
	 */
	public static function waitAllEvents() {
		if (!self::$supported) {
			return;
		}
		while (eio_nreqs()) {
			eio_poll();
		}
	}

	/**
	 * Called when config is updated
	 * @return void
	 */
	public static function updateConfig() {
		if (Daemon::$config->eiosetmaxidle->value !== null) {
			eio_set_max_idle(Daemon::$config->eiosetmaxidle->value);
		}
		if (Daemon::$config->eiosetmaxparallel->value !== null) {
			eio_set_max_parallel(Daemon::$config->eiosetmaxparallel->value);
		}
		if (Daemon::$config->eiosetmaxpollreqs->value !== null) {
			eio_set_max_poll_reqs(Daemon::$config->eiosetmaxpollreqs->value);
		}
		if (Daemon::$config->eiosetmaxpolltime->value !== null) {
			eio_set_max_poll_time(Daemon::$config->eiosetmaxpolltime->value);
		}
		if (Daemon::$config->eiosetminparallel->value !== null) {
			eio_set_min_parallel(Daemon::$config->eiosetminparallel->value);
		}
	}

	/**
	 * Sanitize path
	 * @param  string $path Path
	 * @return string       Sanitized path
	 */
	public static function sanitizePath($path) {
		return str_replace(["\x00", "../"], '', $path);
	}

	/**
	 * Prepare value of stat()
	 * @param  mixed $stat Data
	 * @return array hash
	 */
	public static function statPrepare($stat) {
		if ($stat === -1 || !$stat) {
			return -1;
		}
		$stat['type'] = FileSystem::$modeTypes[$stat['mode'] & 0170000];
		return $stat;
	}

	/**
	 * Gets stat() information
	 * @param  string   $path Path
	 * @param  callable $cb   Callback
	 * @param  integer  $pri  Priority
	 * @return resource|true
	 */
	public static function stat($path, $cb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!self::$supported) {
			call_user_func($cb, $path, FileSystem::statPrepare(@stat($path)));
			return true;
		}
		return eio_stat($path, $pri, function ($path, $stat) use ($cb) {
			call_user_func($cb, $path, FileSystem::statPrepare($stat));
		}, $path);
	}

	/**
	 * Unlink file
	 * @param  string   $path Path
	 * @param  callable $cb   Callback
	 * @param  integer  $pri  Priority
	 * @return resource|boolean
	 */
	public static function unlink($path, $cb = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!self::$supported) {
			$r = unlink($path);
			if ($cb) {
				call_user_func($cb, $path, $r);
			}
			return $r;
		}
		return eio_unlink($path, $pri, $cb, $path);
	}

	/**
	 * Rename
	 * @param  string   $path    Path
	 * @param  string   $newpath New path
	 * @param  callable $cb      Callback
	 * @param  integer  $pri     Priority
	 * @return resource|boolean
	 */
	public static function rename($path, $newpath, $cb = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!self::$supported) {
			$r = rename($path, $newpath);
			if ($cb) {
				call_user_func($cb, $path, $newpath, $r);
			}
			return $r;
		}
		return eio_rename($path, $newpath, $pri, $cb, $path);
	}

	/**
	 * statvfs()
	 * @param  string   $path Path
	 * @param  callable $cb   Callback
	 * @param  integer  $pri  Priority
	 * @return resource|false
	 */
	public static function statvfs($path, $cb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!self::$supported) {
			call_user_func($cb, $path, false);
			return false;
		}
		return eio_statvfs($path, $pri, $cb, $path);
	}

	/**
	 * lstat()
	 * @param  string   $path Path
	 * @param  callable $cb   Callback
	 * @param  integer  $pri  Priority
	 * @return resource|true
	 */
	public static function lstat($path, $cb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!self::$supported) {
			call_user_func($cb, $path, FileSystem::statPrepare(lstat($path)));
			return true;
		}
		return eio_lstat($path, $pri, function ($path, $stat) use ($cb) {
			call_user_func($cb, $path, FileSystem::statPrepare($stat));
		}, $path);
	}

	/**
	 * realpath()
	 * @param  string   $path Path
	 * @param  callable $cb   Callback
	 * @param  integer  $pri  Priority
	 * @return resource|true
	 */
	public static function realpath($path, $cb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!self::$supported) {
			call_user_func($cb, $path, realpath($path));
			return true;
		}
		return eio_realpath($path, $pri, $cb, $path);
	}

	/**
	 * sync()
	 * @param  callable $cb  Callback
	 * @param  integer  $pri Priority
	 * @return resource|false
	 */
	public static function sync($cb = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!self::$supported) {
			if ($cb) {
				call_user_func($cb, false);
			}
			return false;
		}
		return eio_sync($pri, $cb);
	}

	/**
	 * statfs()
	 * @param  callable $cb  Callback
	 * @param  integer  $pri Priority
	 * @return resource|false
	 */
	public static function syncfs($cb = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!self::$supported) {
			if ($cb) {
				call_user_func($cb, false);
			}
			return false;
		}
		return eio_syncfs($pri, $cb);
	}

	/**
	 * touch()
	 * @param  string   $path  Path
	 * @param  integer  $mtime Last modification time
	 * @param  integer  $atime Last access time
	 * @param  callable $cb    Callback
	 * @param  integer  $pri   Priority
	 * @return resource|boolean
	 */
	public static function touch($path, $mtime, $atime = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!FileSystem::$supported) {
			$r = touch($path, $mtime, $atime);
			if ($cb) {
				call_user_func($cb, $r);
			}
			return $r;
		}
		return eio_utime($path, $atime, $mtime, $pri, $cb, $path);
	}

	/**
	 * Removes empty directory
	 * @param  string   $path Path
	 * @param  callable $cb   Callback
	 * @param  integer  $pri  Priority
	 * @return resource|boolean
	 */
	public static function rmdir($path, $cb = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!FileSystem::$supported) {
			$r = rmdir($path);
			if ($cb) {
				call_user_func($cb, $path, $r);
			}
			return $r;
		}
		return eio_rmdir($path, $pri, $cb, $path);
	}

	/**
	 * Creates directory
	 * @param  string   $path Path
	 * @param  integer  $mode Mode (octal)
	 * @param  callable $cb   Callback
	 * @param  integer  $pri  Priority
	 * @return resource|boolean
	 */
	public static function mkdir($path, $mode, $cb = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!FileSystem::$supported) {
			$r = mkdir($path, $mode);
			if ($cb) {
				call_user_func($cb, $path, $r);
			}
			return $r;
		}
		return eio_mkdir($path, $mode, $pri, $cb, $path);
	}

	/**
	 * Readdir()
	 * @param  string   $path  Path
	 * @param  callable $cb    Callback
	 * @param  integer  $flags Flags
	 * @param  integer  $pri   Priority
	 * @return resource|true
	 */
	public static function readdir($path, $cb = null, $flags, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!FileSystem::$supported) {
			$r = glob($path);
			if ($cb) {
				call_user_func($cb, $path, $r);
			}
			return true;
		}
		return eio_readdir($path, $flags, $pri, $cb, $path);
	}

	/**
	 * Truncate file
	 * @param  string   $path   Path
	 * @param  integer  $offset Offset
	 * @param  callable $cb     Callback
	 * @param  integer  $pri    Priority
	 * @return resource|boolean
	 */
	public static function truncate($path, $offset = 0, $cb = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!FileSystem::$supported) {
			$fp = fopen($path, 'r+');
			$r  = $fp && ftruncate($fp, $offset);
			if ($cb) {
				call_user_func($cb, $path, $r);
			}
			return $r;
		}
		return eio_truncate($path, $offset, $pri, $cb, $path);
	}

	/**
	 * sendfile()
	 * @param  mixed    $outfd   File descriptor
	 * @param  string   $path    Path
	 * @param  callable $cb      Callback
	 * @param  callable $startCb Start callback
	 * @param  integer  $offset  Offset
	 * @param  integer  $length  Length
	 * @param  integer  $pri     Priority
	 * @return true              Success
	 */
	public static function sendfile($outfd, $path, $cb, $startCb = null, $offset = 0, $length = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!self::$supported) {
			call_user_func($cb, $path, false);
			return false;
		}
		$noncache = true;
		FileSystem::open($path, 'r!', function ($file) use ($cb, $noncache, $startCb, $path, $pri, $outfd, $offset, $length) {
			if (!$file) {
				call_user_func($cb, $path, false);
				return;
			}
			$file->sendfile($outfd, function ($file, $success) use ($cb, $noncache) {
				call_user_func($cb, $file->path, $success);
				if ($noncache) {
					$file->close();
				}
			}, $startCb, $offset, $length, $pri);

		}, $pri);
		return true;
	}

	/**
	 * Changes ownership of file/directory
	 * @param  string   $path Path
	 * @param  integer  $uid  User ID
	 * @param  integer  $gid  Group ID
	 * @param  callable $cb   Callback
	 * @param  integer  $pri  Priority
	 * @return resource|boolean
	 */
	public static function chown($path, $uid, $gid = -1, $cb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!FileSystem::$supported) {
			$r = chown($path, $uid);
			if ($gid !== -1) {
				$r = $r && chgrp($path, $gid);
			}
			call_user_func($cb, $path, $r);
			return $r;
		}
		return eio_chown($path, $uid, $gid, $pri, $cb, $path);
	}

	/**
	 * Reads whole file
	 * @param  string   $path Path
	 * @param  callable $cb   Callback (Path, Contents)
	 * @param  integer  $pri  Priority
	 * @return resource|true
	 */
	public static function readfile($path, $cb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!FileSystem::$supported) {
			call_user_func($cb, $path, file_get_contents($path));
			return true;
		}
		return FileSystem::open($path, 'r!', function ($file) use ($path, $cb, $pri, $path) {
			if (!$file) {
				call_user_func($cb, $path, false);
				return;
			}
			$file->readAll($cb, $pri);
		}, null, $pri);
	}

	/**
	 * Reads file chunk-by-chunk
	 * @param  string   $path    Path
	 * @param  callable $cb      Callback (Path, Success)
	 * @param  callable $chunkcb Chunk callback (Path, Chunk)
	 * @param  integer  $pri     Priority
	 * @return resource
	 */
	public static function readfileChunked($path, $cb, $chunkcb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!FileSystem::$supported) {
			call_user_func($chunkcb, $path, $r = readfile($path));
			call_user_func($cb, $r !== false);
			return;
		}
		FileSystem::open($path, 'r!', function ($file) use ($path, $cb, $chunkcb, $pri) {
			if (!$file) {
				call_user_func($cb, $path, false);
				return;
			}
			$file->readAllChunked($cb, $chunkcb, $pri);
		}, null, $pri);
	}

	/**
	 * Returns random temporary file name
	 * @param  string $dir    Directory
	 * @param  string $prefix Prefix
	 * @return string         Path
	 */
	public static function genRndTempnam($dir = null, $prefix = 'php') {
		if (!$dir) {
			$dir = sys_get_temp_dir();
		}
		static $n = 0;
		return $dir . '/' . $prefix . str_shuffle(md5(str_shuffle(
														  microtime(true) . chr(mt_rand(0, 0xFF))
														  . Daemon::$process->getPid() . chr(mt_rand(0, 0xFF))
														  . (++$n) . mt_rand(0, mt_getrandmax()))
												  ));
	}

	/**
	 * Returns random temporary file name
	 * @param  string $dir    Directory
	 * @param  string $prefix Prefix
	 * @return string         Path
	 */
	public static function genRndTempnamPrefix($dir, $prefix) {
		if (!$dir) {
			$dir = sys_get_temp_dir();
		}
		return $dir . '/' . $prefix;
	}

	/**
	 * Generates closure tempnam handler
	 * @param  $dir
	 * @param  $prefix
	 * @param  $cb
	 * @param  $tries
	 */
	protected static function tempnamHandler($dir, $prefix, $cb, &$tries) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (++$tries >= 3) {
			call_user_func($cb, false);
			return;
		}
		$path = FileSystem::genRndTempnam($dir, $prefix);
		FileSystem::open($path, 'x+!', function ($file) use ($dir, $prefix, $cb, &$tries) {
			if (!$file) {
				static::tempnamHandler($dir, $prefix, $cb, $tries);
				return;
			}
			call_user_func($cb, $file);
		});
	}

	/**
	 * Obtain exclusive temporary file
	 * @param  string   $dir    Directory
	 * @param  string   $prefix Prefix
	 * @param  callable $cb     Callback (File)
	 * @return resource
	 */
	public static function tempnam($dir, $prefix, $cb) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!FileSystem::$supported) {
			FileSystem::open(tempnam($dir, $prefix), 'w!', $cb);
		}
		$tries = 0;
		static::tempnamHandler($dir, $prefix, $cb, $tries);
	}

	/**
	 * Open file
	 * @param  string   $path  Path
	 * @param  string   $flags Flags
	 * @param  callable $cb    Callback (File)
	 * @param  integer  $mode  Mode (see EIO_S_I* constants)
	 * @param  integer  $pri   Priority
	 * @return resource
	 */
	public static function open($path, $flags, $cb, $mode = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!FileSystem::$supported) {
			$mode = File::convertFlags($flags, true);
			$fd   = fopen($path, $mode);
			if (!$fd) {
				call_user_func($cb, false);
				return false;
			}
			$file = new File($fd, $path);
			call_user_func($cb, $file);
			return true;
		}
		$fdCacheKey = $path . "\x00" . $flags;
		$noncache   = strpos($flags, '!') !== false;
		$flags      = File::convertFlags($flags);
		if (!$noncache && ($item = FileSystem::$fdCache->get($fdCacheKey))) { // cache hit
			$file = $item->getValue();
			if ($file === null) { // operation in progress
				$item->addListener($cb);
			}
			else { // hit
				call_user_func($cb, $file);
			}
			return null;
		}
		elseif (!$noncache) {
			$item = FileSystem::$fdCache->put($fdCacheKey, null);
			$item->addListener($cb);
		}
		return eio_open($path, $flags, $mode, $pri, function ($path, $fd) use ($cb, $flags, $fdCacheKey, $noncache) {
			if ($fd === -1) {
				if ($noncache) {
					call_user_func($cb, false);
				}
				else {
					FileSystem::$fdCache->put($fdCacheKey, false, self::$badFDttl);
				}
				return;
			}
			$file         = new File($fd, $path);
			$file->append = ($flags | EIO_O_APPEND) === $flags;
			if ($file->append) {
				$file->stat(function ($file, $stat) use ($cb, $noncache, $fdCacheKey) {
					$file->offset = $stat['size'];
					if (!$noncache) {
						$file->fdCacheKey = $fdCacheKey;
						FileSystem::$fdCache->put($fdCacheKey, $file);
					}
					else {
						call_user_func($cb, $file);
					}
				});
			}
			else {
				if (!$noncache) {
					$file->fdCacheKey = $fdCacheKey;
					FileSystem::$fdCache->put($fdCacheKey, $file);
				}
				else {
					call_user_func($cb, $file);
				}
			}
		}, $path);
	}
}

if (!defined('EIO_PRI_DEFAULT')) {
	define('EIO_PRI_DEFAULT', 0);
}
