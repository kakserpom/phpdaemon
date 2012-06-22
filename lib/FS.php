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
		if (self::$supported) {
			return eio_stat($path, $pri, $cb, $this);
		}
		call_user_func($this, null, stat($path));
	}
	
	public static function lstat($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if (self::$supported) {
			return eio_lstat($path, $pri, $cb, $this);
		}
		call_user_func($this, null, lstat($path));
	}
	
	public static function sync($cb, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($cb, false);
			return;
		}
 		eio_sync($pri, $cb);
	}
	
	public static function sendfile($out, $in, $offset, $length, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!self::$supported) {
			call_user_func($cb, false);
			return;
		}
 		eio_sendfile($out, $in, $offset, $length, $pri, $cb);
	}
		
	public static function open($path, $mode, $cb, $pri = EIO_PRI_DEFAULT) {
		if (self::$supported) {
			global $res;
			$res = eio_open($path, File::convertOpenMode($mode) , NULL,
			  $pri, function ($arg, $fd) use ($cb, $path) {
				if (!$fd) {
					call_user_func($cb, false);
					return;
				}
				$file = new File($fd);
				$file->path = $path;
				call_user_func($cb, $file);
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
