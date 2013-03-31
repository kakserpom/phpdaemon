<?php
/**
 * File
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class File {

	/**
	 * Priority
	 * @var integer
	 */
	public $priority = 10;

	/**
	 * Chunk size
	 * @var integer
	 */
	public $chunkSize = 4096;

	/**
	 * Stat
	 * @var hash
	 */
	public $stat;

	/**
	 * Current offset 
	 * @var integer
	 */
	public $offset = 0;

	/**
	 * Cache key
	 * @var string
	 */
	public $fdCacheKey;

	/**
	 * Append?
	 * @var boolean
	 */
	public $append;

	/**
	 * Path
	 * @var string
	 */
	public $path;

	/**
	 * Writing?
	 * @var boolean
	 */
	public $writing = false;

	/**
	 * Closed?
	 * @var boolean
	 */
	public $closed = false;

	/**
	 * File descriptor
	 * @var mixed
	 */
	protected $fd;

	/**
	 * Stack of callbacks called when writing is done
	 * @var object StackCallbacks
	 */
	protected $onWriteOnce;

	/**
	 * File constructor
 	 * @param resource File descriptor
	 * @return void
	 */
	public function __construct($fd, $path) {
		$this->fd = $fd;
		$this->path = $path;
		$this->onWriteOnce = new StackCallbacks;
	}

	/**
	 * Get file descriptor
	 * @return mixed File descriptor
	 */	
	public function getFd() {
		return $this->fd;
	}

	/**
	 * Converts string of flags to integer or standard text representation
 	 * @param string Mode
 	 * @param boolean Text?
 	 * @param priority
	 * @return mixed
	 */
	public static function convertFlags($mode, $text = false) {
		$plus = strpos($mode, '+') !== false;
		$sync = strpos($mode, 's') !== false;
		$type = strtr($mode, array('b' => '', '+' => '', 's' => '', '!' => ''));
		if ($text) {
			return $type;
		}
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


	/**
	 * Truncates this file
 	 * @param integer Offset, default is 0
 	 * @param callable Callback
 	 * @param priority
	 * @return resource
	 */
	public function truncate($offset = 0, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd || $this->fd === -1) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			$fp = fopen($this->path, 'r+');
			$r = $fp && ftruncate($fp, $offset);
			if ($cb) {
				call_user_func($cb, $this, $r);
			}
			return $r;
		}
		return eio_ftruncate($this->fd, $offset, $pri, $cb, $this);
	}

	/**
	 * Stat()
 	 * @param callable Callback
 	 * @param priority
	 * @return resource
	 */	
	public function stat($cb, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd || $this->fd === -1) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			call_user_func($cb, $this, FS::statPrepare(fstat($this->fd)));
			return false;
		}
		if ($this->stat) {
			call_user_func($cb, $this, $this->stat);
			return true;
		}
		return eio_fstat($this->fd, $pri, function ($file, $stat) use ($cb) {
			$stat = FS::statPrepare($stat);
			$file->stat = $stat;
			call_user_func($cb, $file, $stat);
		}, $this);
	}

	/**
	 * Stat() non-cached
 	 * @param callable Callback
 	 * @param priority
	 * @return resource
	 */	
	public function statRefresh($cb, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd || $this->fd === -1) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			call_user_func($cb, $this, FS::statPrepare(fstat($this->fd)));
			return true;
		}
		return eio_fstat($this->fd, $pri, function ($file, $stat) use ($cb) {
			$stat = FS::statPrepare($stat);
			$file->stat = $stat;
			call_user_func($cb, $file, $stat);
		}, $this);
	}
	
	/**
	 * Statvfs()
 	 * @param callable Callback
 	 * @param priority
	 * @return resource
	 */	
	public function statvfs($cb, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if ($this->statvfs) {
			call_user_func($cb, $this, $this->statvfs);
			return true;
		}
		return eio_fstatvfs($this->fd, $pri, function ($file, $stat) use ($cb) {
			$file->statvfs = $stat;
			call_user_func($cb, $file, $stat);
		}, $this);
	}

	/**
	 * Sync()
 	 * @param callable Callback
 	 * @param priority
	 * @return resource
	 */	
	public function sync($cb, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			call_user_func($cb, $this, true);
			return false;
		}
		return eio_fsync($this->fd, $pri, $cb, $this);
	}
	

	/**
	 * Datasync()
 	 * @param callable Callback
 	 * @param priority
	 * @return resource
	 */	
	public function datasync($cb, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			call_user_func($cb, $this, true);
			return false;
		}
		return eio_fdatasync($this->fd, $pri, $cb, $this);
	}

	/**
	 * Writes data to file
	 * @param string Data
 	 * @param callable Callback
 	 * @param [integer Offset
 	 * @param priority
	 * @return resource
	 */		
	public function write($data, $cb = null, $offset = null, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			if ($offset !== null) {
				fseek($data, $offset);
			}
			$r = fwrite($this->fd, $data);
			if ($cb) {
				call_user_func($cb, $this, $r);
			}
			return false;
		}
		if ($cb !== null) {
			$this->onWriteOnce->push($cb);
		}
		$l = strlen($data);
		if ($offset === null) {
			$offset = $this->offset;
			$this->offset += $l;
		}
		$this->writing = true;
		$res = eio_write($this->fd, $data, $l, $offset, $pri, function ($file, $result) {
			$this->writing = false;
			$this->onWriteOnce->executeAll($file, $result);
		}, $this);
		return $res;
	}
	

	/**
	 * Changes ownership of this file
	 * @param integer User ID
	 * @param integer Group ID
 	 * @param callable Callback
 	 * @param priority
	 * @return resource
	 */
	public function chown($uid, $gid = -1, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			$r = chown($this->path, $uid);
			if ($gid !== -1) {
				$r = $r && chgrp($this->path, $gid);
			}
			if ($cb) {
				call_user_func($cb, $this, $r);
			}
			return false;
		}
		return eio_fchown($this->fd, $uid, $gid, $pri, $cb, $this);
	}
	
	/**
	 * touch()
	 * @param integer Last modification time
	 * @param integer Last access time
 	 * @param callable Callback
 	 * @param priority
	 * @return resource
	 */
	public function touch($mtime, $atime = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			$r = touch($this->path, $mtime, $atime);
			if ($cb) {
				call_user_func($cb, $this, $r);
			}
			return false;
		}
		return eio_futime($this->fd, $atime, $mtime, $pri, $cb, $this);
	}
	
	/**
	 * Clears cache of stat() and statvfs()
	 * @return void
	 */
	public function clearStatCache() {
		$this->stat = null;
		$this->statvfs = null;
	}
	
	/**
	 * Reads data from file
	 * @param integer Length
	 * @param [integer Offset
 	 * @param callable Callback
 	 * @param priority
	 * @return resource
	 */
	public function read($length, $offset = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		$this->offset += $length;
		eio_read(
			$this->fd,
			$length,
			$offset !== null ? $offset : $this->offset,
			$pri,
			$cb ? $cb: $this->onRead,
			$this
		);
		return true;
	}


	/**
	 * sendfile()
	 * @param mixed File descriptor
	 * @param callable Start callback
	 * @param integer Offset
	 * @param integer Length
	 * @param priority
	 * @return boolean Success
	 */
	public function sendfile($outfd, $cb, $startCb = null, $offset = 0, $length = null, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		static $chunkSize = 1024;
		$ret = true;
		$handler = function ($file, $sent = -1) use (&$ret, $outfd, $cb, &$handler, &$offset, &$length, $pri, $chunkSize) {
			if ($outfd instanceof IOStream) {
				if ($outfd->isFreed()) {
					call_user_func($cb, $file, false);
					return;
				}
				$ofd = $outfd->getFd();
			} else {
				$ofd = $outfd;
			}
			if (!$ret) {
				call_user_func($cb, $file, false);
				return;
			}
			if ($sent === -1) {
				$sent = 0;
			}
			$offset += $sent;
			$length -= $sent;
			if ($length <= 0) {
				call_user_func($cb, $file, true);
				return;
			}
			if (!$ofd) {
				call_user_func($cb, $file, false);
				return;
			}
			$c = min($chunkSize, $length);
			$ret = eio_sendfile($ofd, $file->fd, $offset, $c, $pri, $handler, $file);
		};
		if ($length !== null) {
			if ($startCb !== null) {
				if (!call_user_func($startCb, $file, $length, $handler)) {
					$handler($this);
				}
			} else {
				$handler($this);
			}
			return true;
		}
		$this->statRefresh(function ($file, $stat) use ($startCb, $handler, &$length) {
			$length = $stat['size'];
			if ($startCb !== null) {
				if (!call_user_func($startCb, $file, $length, $handler)) {
					$handler($file);
				}
			} else {
				$handler($file);
			}
		}, $pri);
		return true;
	}

	/**
	 * readahead()
	 * @param integer Length
	 * @param integer Offset
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */
	public function readahead($length, $offset = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FS::$supported) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		$this->offset += $length;
		return eio_readahead(
			$this->fd,
			$length,
			$offset !== null ? $offset : $this->pos,
			$pri,
			$cb,
			$this
		);
	}


	/**
	 * Reads whole file
	 * @param callable Callback
	 * @param priority
	 * @return boolean Success
	 */
	public function readAll($cb, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		$this->statRefresh(function ($file, $stat) use ($cb, $pri) {
			if (!$stat) {
				if ($cb) {
					call_user_func($cb, $file, false);
				}
				return;
			}
			$offset = 0;
			$buf = '';
			$size = $stat['size'];
			$handler = function ($file, $data) use ($cb, &$handler, $size, &$offset, $pri, &$buf) {
				$buf .= $data;
				$offset += strlen($data);
				$len = min($file->chunkSize, $size - $offset);
				if ($offset >= $size) {
					if ($cb) {
						call_user_func($cb, $file, $buf);
					}
					return;
				}
				eio_read($file->fd, $len, $offset, $pri, $handler, $this);
			};
			eio_read($file->fd, min($file->chunkSize, $size), 0, $pri, $handler, $file);
		}, $pri);
		return true;
	}
	
	/**
	 * Reads file chunk-by-chunk
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */
	public function readAllChunked($cb = null, $chunkcb = null, $pri = EIO_PRI_DEFAULT) {
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		return $this->statRefresh(function ($file, $stat) use ($cb, $chunkcb, $pri) {
			if (!$stat) {
				call_user_func($cb, $file, false);
				return;
			}
			$offset = 0;
			$size = $stat['size'];
			$handler = function ($file, $data) use ($cb, $chunkcb, &$handler, $size, &$offset, $pri) {
				call_user_func($chunkcb, $file, $data);
				$offset += strlen($data);
				$len = min($file->chunkSize, $size - $offset);
				if ($offset >= $size) {
					call_user_func($cb, $file, true);
					return;
				}
				eio_read($file->fd, $len, $offset, $pri, $handler, $file);
			};
			eio_read($file->fd, min($file->chunkSize, $size), $offset, $pri, $handler, $file);
		}, $pri);
	}

	/**
	 * toString handler
	 * @return string
	 */
	public function __toString() {
		return $this->path;
	}

	/**
	 * Set chunk size
	 * @param integer Chunk size
	 * @return void
	 */
	public function setChunkSize($n) {
		$this->chunkSize = $n;
	}	

	/**
	 * Move pointer to arbitrary position
	 * @param integer offset
	 * @param callable Callback
	 * @param priority
	 * @return resource
	 */
	public function seek($offset, $cb, $pri = EIO_PRI_DEFAULT) {
		if (!EIO::$supported) {
			fseek($this->fd, $offset);
			return false;
		}
		return eio_seek($this->fd, $offset, $pri, $cb, $this);
	}


	/**
	 * Get current pointer position
	 * @return integer
	 */
	public function tell() {
		if (EIO::$supported) {
			return $this->pos;
		}
		return ftell($this->fd);
	}
	


	/**
	 * Close the file
	 * @return resource
	 */
	public function close() {
		if ($this->closed) {
			return false;
		}
		$this->closed = true;
		if ($this->fdCacheKey !== null) {
			FS::$fdCache->invalidate($this->fdCacheKey);
		}
		if ($this->fd === null) {
			return false;
		}

		if (!FS::$supported) {
			fclose($this->fd);
			return false;
		}
		$r = eio_close($this->fd, EIO_PRI_MAX);
		$this->fd = null;
		return $r;
	}

	/**
	 * Destructor
	 * @return void
	 */
	public function __destruct() {
		$this->close();
	}
}
