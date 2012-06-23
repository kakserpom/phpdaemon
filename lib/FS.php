t<?php

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
	public static function init() {
		if (!self::$supported = is_callable('eio_poll')) {
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
	
	public static function stat($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($this, $path, stat($path));
		}
		return eio_stat($path, $pri, $cb, $path);
	}
	
	public static function statvfs($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($this, $path, false);
			return;
		}
		eio_statvfs($path, $pri, $cb, $path);
	}
	
	public static function lstat($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($this, $path, lstat($path));
			return;
		}
		return eio_lstat($path, $pri, $cb, $path);
	}
	
	public static function sync($cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			if ($cb) {
				call_user_func($cb, false);
			}
			return;
		}
 		eio_sync($pri, $cb);
	}
	
	public static function syncfs($cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			if ($cb) {
				call_user_func($cb, false);
			}
			return;
		}
 		eio_syncfs($pri, $cb);
	}
	
	public function touch($path, $mtime, $atime = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$r = touch($path, $mtime, $atime);
			if ($cb) {
				call_user_func($cb, $r);
			}
			return;
		}
		eio_utime($path, $atime, $mtime, $pri, $cb, $path);
	}
	
	public function rmdir($path, $mtime, $atime = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$r = rmdir($path);
			if ($cb) {
				call_user_func($cb, $path, $r);
			}
			return;
		}
		eio_rmdir($path, $pri, $cb, $path);
	}
	
	
	public function truncate($path, $offset = 0, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$fp = fopen($path, 'r+');
			$r = $fp && ftruncate($fp, $offset);
			if ($cb) {
				call_user_func($cb, $path, $r);
			}
			return;
		}
		eio_truncate($path, $offset, $pri, $cb, $path);
	}

	public static function sendfile($out, $in, $offset, $length, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($cb, false);
			return;
		}
 		eio_sendfile($out, $in, $offset, $length, $pri, $cb);
	}
	
	public function chown($path, $uid, $gid = -1, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$r = chown($path, $uid);
			if ($gid !== -1) {
				$r = $r && chgrp($path, $gid);
			}
			call_user_func($cb, $path, $r);
			return;
		}
		eio_chown($path, $uid, $gid, $pri, $cb, $path);
	}
		
	public static function open($path, $mode, $cb, $pri = EIO_PRI_DEFAULT) {
		if (self::$supported) {
			global $res;
			$mode = File::convertOpenMode($mode);
			$res = eio_open($path, $mode , NULL,
			  $pri, function ($arg, $fd) use ($cb, $path, $mode) {
				if (!$fd) {
					call_user_func($cb, false);
					return;
				}
				$file = new File($fd);
				$file->append = ($mode | EIO_O_APPEND) === $mode;
				$file->path = $path;
				if ($file->append) {
					$file->stat(function($file, $stat) use ($cb) {
						$file->pos = $stat['st_size'];
						call_user_func($cb, $file);
					}):
				} else {
					call_user_func($cb, $file);
				}
			}, NULL);
			return;
		}
		$fd = fopen($path, $mode);
		if (!$fd) {
			call_user_func($cb, false);
		}
		stream_set_blocking($fd, 0);
		$file = new File($fd);
		$file->path = $path;
		call_user_func($cb, $file);
	}
}
