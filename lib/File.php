<?php

/**
 * Connection
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class File extends IOStream {
	public $priority = 10; // low priority
	public $pos = 0;
	public $pieceSize = 4095;
	public $stat;
		
	public static function convertOpenMode($mode) {
		$plus = strpos($mode, '+') !== false;
		$sync = strpos($mode, 's') !== false;
		$type = strtr($mode, array('b' => '', '+' => '', 's' => ''));
		$types = array(
			'r' =>  $plus ? EIO_O_RDWR : EIO_O_RDONLY,
			'w' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_CREAT | EIO_O_TRUNC,
			'a' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_CREAT | EIO_O_APPEND,
			'x' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_EXCL | EIO_O_CREAT,
			'c' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_CREAT,
		);
		$m = $types[$type];
		if ($sync) {
			$m |= EIO_O_FSYNC;
		}
		return $m;
	}

	
	public function stat($cb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			call_user_func($cb, $this, fstat($this->fd));
		}
		if ($this->stat) {
			call_user_func($cb, $this, $this->stat);
		} else {
			eio_fstat($this->fd, $pri, function ($file, $stat) use ($cb) {
				if (!isset($stat['st_size'])) { // DIRTY HACK! DUE TO BUG IN PECL-EIO
					Daemon::log('eio: stat() performance compromised. Consider upgrading pecl-eio');
					$stat['st_size'] = filesize($file->path);
				}
				$file->stat = $stat;
				call_user_func($cb, $file, $stat);
			}, $this);		
		}
	}
	
	public function statvfs($cb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			call_user_func($cb, $this, false);
		}
		if ($this->statvfs) {
			call_user_func($cb, $this, $this->statvfs);
		} else {
			eio_fstatvfs($this->fd, $pri, function ($file, $stat) use ($cb) {
				$file->statvfs = $stat;
				call_user_func($cb, $file, $stat);
			}, $this);		
		}
	}

	public function sync($cb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			call_user_func($cb, $this, true);
			return;
		}
		eio_fsync($this->fd, $pri, $cb, $this);
	}
	
	public function datasync($cb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			call_user_func($cb, $this, true);
			return;
		}
		eio_fdatasync($this->fd, $pri, $cb, $this);
	}

	public function chown($uid, $gid = -1, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$r = chown($path, $uid);
			if ($gid !== -1) {
				$r = $r && chgrp($path, $gid);
			}
			call_user_func($cb, $this, $r);
			return;
		}
		eio_fchown($this->fd, $uid, $gid, $pri, $cb, $this);
	}
	
	public function touch($mtime, $atime = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!FS::$supported) {
			$r = touch($this->path, $mtime, $atime);
			if ($cb) {
				call_user_func($cb, $this, $r);
			}
			return;
		}
		eio_futime($this->fd, $atime, $mtime, $pri, $cb, $this);
	}
	

	public function clearStatCache() {
		$this->stat = null;
		$this->statvfs = null;
	}
	public function read($length, $offset = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!$cb && !$this->onRead) {
			return false;
		}
		$this->pos += $length;
		$file = $this;
		eio_read(
			$this->fd,
			$length,
			$offset !== null ? $offset : $this->pos,
			$pri,
			$cb ? $cb: $this->onRead,
			$this
		);
		return true;
	}

	public function readAll($cb = null, $pri = EIO_PRI_DEFAULT) {
		$this->stat(function ($file, $stat) use ($cb, $pri) {
			if (!$stat) {
				$cb($file, false);
				return;
			}
			$pos = 0;
			$handler = function ($file, $data) use ($cb, &$handler, $stat, &$pos, $pri) {
				$file->buf .= $data;
				$pos += $this->pieceSize;
				if ($pos >= $stat['st_size']) {
					call_user_func($cb, $file, $file->buf);
					$file->buf = '';
					return;
				}
				eio_read($this->fd, $stat['st_size'] - $pos, $file->pos, $pri, $handler, $this);
			};
			eio_read($this->fd, min($this->pieceSize, $stat['st_size']), 0, $pri, $handler, $this);
		});
	}
	
	public function setFd($fd) {
		$this->fd = $fd;
		if (!$this->inited) {
			$this->inited = true;
			$this->init();
		}
	}
	
	public function seek($p) {
		if (EIO::$supported) {
			$this->pos = $p;
			return true;
		}
		fseek($this->fd, $p);
	}
	public function tell() {
		if (EIO::$supported) {
			return $this->pos;
		}
		return ftell($this->fd);
	}
	/**
	 * Read data from the connection's buffer
	 * @param integer Max. number of bytes to read
	 * @return string Readed data
	 */
	/*public function read($n) {
		if (isset($this->readEvent)) {
			if (!isset($this->fd)) {
				return false;
			}
			$read = fread($this->fd, $n);
		} else {
			if (!isset($this->buffer)) {
				return false;
			}
			$read = event_buffer_read($this->buffer, $n);
		}
		if (
			($read === '') 
			|| ($read === null) 
			|| ($read === false)
		) {
			$this->reading = false;
			return false;
		}
		return $read;
	}*/
	
	public function close() {
		$this->closeFd();
	}
	public function closeFd() {
		if (FS::$supported) {
			eio_close($this->fd);
			$this->fd = null;
			return;
		}
		fclose($this->fd);
	}
	
	public function eof() {
		if (
			!$this->EOF && (
				($this->readFD === FALSE) 
				|| feof($this->readFD)
			)
		) {
			$this->onEofEvent();
		}
		elseif (!$this->EOF) {
			$this->onReadEvent();
		}

		return $this->EOF;
	}

	public function onEofEvent() {
		$this->EOF = true;
	
		if ($this->onEOF !== NULL) {
			call_user_func($this->onEOF, $this);
		}
	
		$this->close();
	}
}
