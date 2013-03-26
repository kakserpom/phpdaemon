<?php

/**
 * FS
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class FS {	
	/**
	 * Is EIO supported?
	 * @var boolean
	 */
	public static $supported;

	/**
	 * Main FS event
	 * @var Event
	 */
	public static $ev;

	/**
	 * EIO file descriptor
	 * @var resource
	 */
	public static $fd;

	/**
	 * Mode types
	 * @var hash
	 */
	public static $modeTypes = array(
  		0140000 => 's',
  		0120000 => 'l',
 		0100000 => 'f',
 		0060000 => 'b',
 		0040000 => 'd',
 		0020000 => 'c',
 		0010000 => 'p',
 	);

 	/**
	 * TTL for bad descriptors in seconds
	 * @var integer
	 */
 	public static $badFDttl = 5;

 	/**
	 * File descriptor cache
	 * @var CappedCacheStorage
	 */
	public static $fdCache;

	/**
	 * Maximum number of open files in cache
	 * @var number
	 */
	public static $fdCacheSize = 128;

	/**
	 * Required EIO version
	 * @var string
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
		if (!self::$supported =	Daemon::loadModuleIfAbsent('eio', self::$eioVer)) {
			Daemon::log('FS: missing pecl-eio >= ' . self::$eioVer . '. Filesystem I/O performance compromised. Consider installing pecl-eio. `pecl install http://pecl.php.net/get/eio-' . self::$eioVer . '.tgz`');
			return;
		}
		self::$fdCache = new CappedCacheStorageHits(self::$fdCacheSize);
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
		self::$ev = new Event(Daemon::$process->eventBase, self::$fd, Event::READ | Event::PERSIST, function ($fd, $events, $arg) {
			while (eio_nreqs()) {
	        	eio_poll();
		    }
		});
		self::$ev->add();
	}
	
	/**
	 * Checks if file exists and readable
	 * @param string Path
	 * @return boolean Exists and readable?
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
	 * @param string Path
	 * @return string Sanitized path
	 */
	public static function sanitizePath($path) {
		$path = str_replace("\x00", '', $path);
		$path = str_replace("../", '', $path);
		return $path;
	}
	
	/**
	 * Prepare value of stat()
	 * @return hash
	 */
	public static function statPrepare($stat) {
		if ($stat === -1 || !$stat) {
			return -1;
		}
		$stat['type'] = FS::$modeTypes[$stat['mode'] & 0170000];
		return $stat;
	}

	/**
	 * Gets stat() information
	 * @param string Path
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */
	public static function stat($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($cb, $path, FS::statPrepare(@stat($path)));
			return true;
		}
		return eio_stat($path, $pri, function($path, $stat) use ($cb) {call_user_func($cb, $path, FS::statPrepare($stat));}, $path);
	}

	/**
	 * Unlink file
	 * @param string Path
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */	
	public static function unlink($path, $cb = null, $pri = EIO_PRI_DEFAULT) {
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
	 * @param string Path
	 * @param string New path
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */
	public static function rename($path, $newpath, $cb = null, $pri = EIO_PRI_DEFAULT) {
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
	 * @param string Path
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */
	public static function statvfs($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($cb, $path, false);
			return false;
		}
		return eio_statvfs($path, $pri, $cb, $path);
	}
	
	/**
	 * lstat()
	 * @param string Path
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */
	public static function lstat($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($cb, $path, FS::statPrepare(lstat($path)));
			return true;
		}
		return eio_lstat($path, $pri, function($path, $stat) use ($cb) {call_user_func($cb, $path, FS::statPrepare($stat));}, $path);
	}
	

	/**
	 * realpath()
	 * @param string Path
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */
	public static function realpath($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($cb, $path, realpath($path));
			return true;
		}
		return eio_realpath($path, $pri, $cb, $path);
	}
	

	/**
	 * sync()
	 * @param string Path
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */
	public static function sync($cb = null, $pri = EIO_PRI_DEFAULT) {
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
	 * @param string Path
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */
	public static function syncfs($cb = null, $pri = EIO_PRI_DEFAULT) {
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
	 * @param string Path
	 * @param integer Last modification time
	 * @param integer Last access time
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */
	public static function touch($path, $mtime, $atime = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
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
	 * @param string Path
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */
	public static function rmdir($path, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
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
	 * @param string Path
	 * @param octal Mode
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */
	public static function mkdir($path, $mode, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
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
	 * @param string Path
	 * @param callable Callback
	 * @param integer Flags
	 * @param priority
	 * @return resource
	 */
	public static function readdir($path, $cb = null, $flags,  $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
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
	 * @param string Path
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */
	public static function truncate($path, $offset = 0, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$fp = fopen($path, 'r+');
			$r = $fp && ftruncate($fp, $offset);
			if ($cb) {
				call_user_func($cb, $path, $r);
			}
			return $r;
		}
		return eio_truncate($path, $offset, $pri, $cb, $path);
	}

	/**
	 * sendfile()
	 * @param mixed File descriptor
	 * @param string Path
	 * @param callable Start callback
	 * @param integer Offset
	 * @param integer Length
	 * @param priority
	 * @return boolean Success
	 */
	public static function sendfile($outfd, $path, $cb, $startCb = null, $offset = 0, $length = null, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($cb, $path, false);
			return false;
		}
		$noncache = true;
		FS::open($path, 'r!', function ($file) use ($cb, $noncache, $startCb, $path, $pri, $outfd, $offset, $length) {
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
	 * @param string Path
	 * @param integer User ID
	 * @param integer Group ID
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */	
	public static function chown($path, $uid, $gid = -1, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
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
	 * @param string Path
	 * @param callable Callback (Path, Contents)
	 * @param priority
	 * @return resource
	 */
	public static function readfile($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			call_user_func($cb, $path, file_get_contents($path));
			return true;
		}
		return FS::open($path, 'r!', function ($file) use ($path, $cb, $pri, $path) {
			if (!$file) {
				call_user_func($cb, $path, false);
				return;
			}
			$file->readAll($cb, $pri);
		}, null, $pri);
	}
	

	/**
	 * Reads file chunk-by-chunk
	 * @param string Path
	 * @param callable Callback (Path, Success)
	 * @param callable Chunk callback (Path, Chunk)
 	 * @param priority
	 * @return resource
	 */
	public static function readfileChunked($path, $cb, $chunkcb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			call_user_func($chunkcb, $path, $r = readfile($path));
			call_user_func($cb, $r !== false);
			return;
		}
		FS::open($path, 'r!', function ($file) use ($path, $cb, $chunkcb, $pri) {
			if (!$file) {
				call_user_func($cb, $path, false);
				return;
			}
			$file->readAllChunked($cb, $chunkcb, $pri);
		}, null, $pri);
	}
	

	/**
	 * Returns random temporary file name
	 * @param string Directory
	 * @param string Prefix
	 * @return string Path
	 */
	public static function genRndTempnam($dir, $prefix) {
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
	 * Obtain exclusive temporary file
	 * @param string Directory
	 * @param string Prefix
	 * @param callable Callback (File)
 	 * @param priority
	 * @return resource
	 */
	public static function tempnam($dir, $prefix, $cb) {
		if (!FS::$supported) {
			FS::open(tempnam($dir, $prefix), 'w!', $cb);
		}
		$tries = 0;
		$handler = function() use ($dir, $prefix, &$handler, $cb, &$tries) {
			if (++$tries >= 3) {
				call_user_func($cb, false);
				return;
			}
			$path = FS::genRndTempnam($dir, $prefix);
			FS::open($path, 'x+!', function($file) use ($handler, $cb) {
				if (!$file) {
					$handler();
				}
				call_user_func($cb, $file);
			});
		};
		$handler();
	}

	/**
	 * Open file
	 * @param string Path
	 * @param string Flags
	 * @param callable Callback (File)
	 * @param integer Mode (see EIO_S_I* constants)
 	 * @param priority
	 * @return resource
	 */	
	public static function open($path, $flags, $cb, $mode = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$mode = File::convertFlags($flags, true);
			$fd = fopen($path, $mode);
			if (!$fd) {
				call_user_func($cb, false);
				return false;
			}
			$file = new File($fd, $path);
			call_user_func($cb, $file);
			return true;
		}
		$fdCacheKey = $path . "\x00" . $flags;
		$noncache = strpos($flags, '!') !== false;
		$flags = File::convertFlags($flags);
		if (!$noncache && ($item = FS::$fdCache->get($fdCacheKey))) { // cache hit
			$file = $item->getValue();
			if ($file === null) { // operation in progress
				$item->addListener($cb);
			} else { // hit
				call_user_func($cb, $file);
			}
			return null;
		} elseif (!$noncache) {
			$item = FS::$fdCache->put($fdCacheKey, null);
			$item->addListener($cb);
		}
		return eio_open($path, $flags, $mode, $pri, function ($path, $fd) use ($cb, $flags, $fdCacheKey, $noncache) {
			if ($fd === -1) {
				if ($noncache) {
					call_user_func($cb, false);
				} else {
					FS::$fdCache->put($fdCacheKey, false, self::$badFDttl);
				}
				return;
			}
			$file = new File($fd, $path);
			$file->append = ($flags | EIO_O_APPEND) === $flags;
			if ($file->append) {
				$file->stat(function($file, $stat) use ($cb, $noncache, $fdCacheKey) {
					$file->pos = $stat['size'];
					if (!$noncache) {
						$file->fdCacheKey = $fdCacheKey;
						FS::$fdCache->put($fdCacheKey, $file);
					} else {
						call_user_func($cb, $file);
					}
				});
			} else {
				if (!$noncache) {
					$file->fdCacheKey = $fdCacheKey;
					FS::$fdCache->put($fdCacheKey, $file);
				} else {
					call_user_func($cb, $file);
				}
			}
		}, $path);
	}
}
if (!defined('EIO_PRI_DEFAULT')) {
	define('EIO_PRI_DEFAULT', 0);
}
