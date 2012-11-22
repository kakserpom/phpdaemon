<?php

/**
 * FS
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class FS {	
	public static $supported;
	public static $ev;
	public static $fd;
	public static $modeTypes = array(
  		0140000 => 's',
  		0120000 => 'l',
 		0100000 => 'f',
 		0060000 => 'b',
 		0040000 => 'd',
 		0020000 => 'c',
 		0010000 => 'p',
 	);
 	public static $badFDttl = 5;
	public static $fdCache;
	public static $fdCacheSize = 128;
	public static function init() {
		if (!self::$supported = extension_loaded('eio')) {
			Daemon::log('FS: missing pecl-eio, Filesystem I/O performance compromised. Consider installing pecl-eio.');
			return;
		}
		self::$fdCache = new CappedCacheStorageHits(self::$fdCacheSize);
		eio_init();
	}
	public static function initEvent() {
		if (!self::$supported) {
			return;
		}
		self::updateConfig();
		self::$ev = event_new();
		self::$fd = eio_get_event_stream();
		event_set(self::$ev, self::$fd, EV_READ | EV_PERSIST, function ($fd, $events, $arg) {
			if (eio_nreqs()) {
	        	eio_poll();
		    }
		});
		event_base_set(self::$ev, Daemon::$process->eventBase);
		event_add(self::$ev);
	}
	
	public static function waitAllEvents() {
		if (!self::$supported) {
			return;
		}
		while ($n = eio_nreqs()) {
		    eio_poll();
		}
	}
	
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
	
	public static function sanitizePath($path) {
		$path = str_replace("\x00", '', $path);
		$path = str_replace("../", '', $path);
		return $path;
	}
	
	public static function statPrepare($stat) {
		if ($stat === -1 || !$stat) {
			return -1;
		}
		$stat['type'] = FS::$modeTypes[$stat['mode'] & 0170000];
		return $stat;
		
	}
	public static function stat($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($cb, $path, FS::statPrepare(stat($path)));
			return;
		}
		return eio_stat($path, $pri, function($path, $stat) use ($cb) {call_user_func($cb, $path, FS::statPrepare($stat));}, $path);
	}
	
	public static function unlink($path, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			$r = unlink($path);
			if ($cb) {
				call_user_func($cb, $path, $r);
			}
			return;
		}
		return eio_unlink($path, $pri, $cb, $path);
	}
	
	public static function statvfs($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($cb, $path, false);
			return;
		}
		return eio_statvfs($path, $pri, $cb, $path);
	}
	
	public static function lstat($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($cb, $path, FS::statPrepare(lstat($path)));
			return;
		}
		return eio_lstat($path, $pri, function($path, $stat) use ($cb) {call_user_func($cb, $path, FS::statPrepare($stat));}, $path);
	}
	
	public static function realpath($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($cb, $path, realpath($path));
			return;
		}
		return eio_realpath($path, $pri, $cb, $path);
	}
	
	public static function sync($cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			if ($cb) {
				call_user_func($cb, false);
			}
			return;
		}
 		return eio_sync($pri, $cb);
	}
	
	public static function syncfs($cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			if ($cb) {
				call_user_func($cb, false);
			}
			return;
		}
 		return eio_syncfs($pri, $cb);
	}
	
	public static function touch($path, $mtime, $atime = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$r = touch($path, $mtime, $atime);
			if ($cb) {
				call_user_func($cb, $r);
			}
			return;
		}
		return eio_utime($path, $atime, $mtime, $pri, $cb, $path);
	}
	
	public static function rmdir($path, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$r = rmdir($path);
			if ($cb) {
				call_user_func($cb, $path, $r);
			}
			return;
		}
		return eio_rmdir($path, $pri, $cb, $path);
	}
	
	public static function mkdir($path, $mode, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$r = mkdir($path, $mode);
			if ($cb) {
				call_user_func($cb, $path, $r);
			}
			return;
		}
		return eio_mkdir($path, $mode, $pri, $cb, $path);
	}
	
	public static function readdir($path, $cb = null, $flags,  $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$r = glob($path);
			if ($cb) {
				call_user_func($cb, $path, $r);
			}
			return;
		}
		return eio_readdir($path, $flags, $pri, $cb, $path);
	}
	
	
	public static function truncate($path, $offset = 0, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$fp = fopen($path, 'r+');
			$r = $fp && ftruncate($fp, $offset);
			if ($cb) {
				call_user_func($cb, $path, $r);
			}
			return;
		}
		return eio_truncate($path, $offset, $pri, $cb, $path);
	}

	public static function sendfile($outfd, $path, $cb, $offset = 0, $length = null, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($cb, false);
			return;
		}
		FS::open($path, 'r', function ($file) use ($cb, $path, $pri, $outfd, $offset, $length) {
			if (!$file) {
				call_user_func($cb, $path, false);
				return;
			}
			$file->sendfile($outfd, $cb, $offset, $length, $pri);

		}, $pri);
	}
	
	public static function chown($path, $uid, $gid = -1, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$r = chown($path, $uid);
			if ($gid !== -1) {
				$r = $r && chgrp($path, $gid);
			}
			call_user_func($cb, $path, $r);
			return;
		}
		return eio_chown($path, $uid, $gid, $pri, $cb, $path);
	}
	
	public static function readfile($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			call_user_func($cb, $path, file_get_contents($path));
			return;
		}
		FS::open($path, 'r', function ($file) use ($cb, $pri, $path) {
			if (!$file) {
				call_user_func($cb, $path, false);
				return;
			}
			$file->readAll($cb, $pri);
		}, null, $pri);
	}
	
	public static function readfileChunked($path, $cb, $chunkcb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			call_user_func($chunkcb, $path, $r = readfile($path));
			call_user_func($cb, $r !== false);
			return;
		}
		FS::open($path, 'r', function ($file) use ($cb, $chunkcb, $pri) {
			if (!$file) {
				call_user_func($cb, $file->path, false);
				return;
			}
			$file->readAllChunked($cb, $chunkcb, $pri);
		}, null, $pri);
	}
	
	public static function tempnam($dir, $prefix) {
		return tempnam($dir, $prefix);
	}
	
	public static function open($path, $flags, $cb, $mode = null, $pri = EIO_PRI_DEFAULT) {
		if (self::$supported) {
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
				return;
			} elseif (!$noncache) {
				$item = FS::$fdCache->put($fdCacheKey, null);
				$item->addListener($cb);
			}
			return eio_open($path, $flags , $mode,
			  $pri, function ($arg, $fd) use ($cb, $path, $flags, $fdCacheKey, $noncache) {
			  	
				if (!$fd) {
					if ($noncache) {
						call_user_func($cb, false);
					} else {
						FS::$fdCache->put($fdCacheKey, false, self::$badFDttl);
					}
					return;
				}
				$file = new File($fd);
				$file->append = ($flags | EIO_O_APPEND) === $flags;
				$file->path = $path;
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
		$mode = File::convertFlags($flags, true);
		$fd = fopen($path, $mode);
		if (!$fd) {
			call_user_func($cb, false);
			return;
		}
		$file = new File($fd);
		$file->path = $path;
		call_user_func($cb, $file);
	}
}
if (!defined('EIO_PRI_DEFAULT')) {
	define('EIO_PRI_DEFAULT', 0);
}