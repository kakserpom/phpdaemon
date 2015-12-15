<?php
namespace PHPDaemon\FS;

use PHPDaemon\Network\IOStream;
use PHPDaemon\Structures\StackCallbacks;
use PHPDaemon\Core\CallbackWrapper;

/**
 * File
 * @package PHPDaemon\FS
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class File {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var integer Priority
	 */
	public $priority = 10;

	/**
	 * @var integer Chunk size
	 */
	public $chunkSize = 4096;

	/**
	 * @var string $stat hash Stat
	 */
	protected $stat;

	/**
	 * @var array Cache of statvfs()
	 */
	protected $statvfs;

	/**
	 * @var integer Current offset
	 */
	public $offset = 0;

	/**
	 * @var string Cache key
	 */
	public $fdCacheKey;

	/**
	 * @var boolean Append?
	 */
	public $append;

	/**
	 * @var string Path
	 */
	public $path;

	/**
	 * @var boolean Writing?
	 */
	public $writing = false;

	/**
	 * @var boolean Closed?
	 */
	public $closed = false;

	/**
	 * @var object File descriptor
	 */
	protected $fd;

	/**
	 * @var PHPDaemon\Structures\StackCallbacks Stack of callbacks called when writing is done
	 */
	protected $onWriteOnce;

	/**
	 * File constructor
	 * @param resource $fd   Descriptor
	 * @param string   $path Path
	 */
	public function __construct($fd, $path) {
		$this->fd          = $fd;
		$this->path        = $path;
		$this->onWriteOnce = new StackCallbacks;
	}

	/**
	 * Get file descriptor
	 * @return resource File descriptor
	 */
	public function getFd() {
		return $this->fd;
	}

	/**
	 * Converts string of flags to integer or standard text representation
	 * @param  string  $mode Mode
	 * @param  boolean $text Text?
	 * @return mixed
	 */
	public static function convertFlags($mode, $text = false) {
		$plus = strpos($mode, '+') !== false;
		$sync = strpos($mode, 's') !== false;
		$type = strtr($mode, ['b' => '', '+' => '', 's' => '', '!' => '']);
		if ($text) {
			return $type;
		}
		$types = [
			'r' => $plus ? EIO_O_RDWR : EIO_O_RDONLY,
			'w' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_CREAT | EIO_O_TRUNC,
			'a' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_CREAT | EIO_O_APPEND,
			'x' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_EXCL | EIO_O_CREAT,
			'c' => ($plus ? EIO_O_RDWR : EIO_O_WRONLY) | EIO_O_CREAT,
		];
		$m     = $types[$type];
		if ($sync) {
			$m |= EIO_O_FSYNC;
		}
		return $m;
	}

	/**
	 * Truncates this file
	 * @param  integer  $offset Offset. Default is 0
	 * @param  callable $cb     Callback
	 * @param  integer  $pri    Priority
	 * @return resource|boolean
	 */
	public function truncate($offset = 0, $cb = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!$this->fd || $this->fd === -1) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FileSystem::$supported) {
			$fp = fopen($this->path, 'r+');
			$r  = $fp && ftruncate($fp, $offset);
			if ($cb) {
				call_user_func($cb, $this, $r);
			}
			return $r;
		}
		return eio_ftruncate($this->fd, $offset, $pri, $cb, $this);
	}

	/**
	 * Stat()
	 * @param  callable $cb  Callback
	 * @param  integer  $pri Priority
	 * @return resource|boolean
	 */
	public function stat($cb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!$this->fd || $this->fd === -1) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FileSystem::$supported) {
			call_user_func($cb, $this, FileSystem::statPrepare(fstat($this->fd)));
			return false;
		}
		if ($this->stat) {
			call_user_func($cb, $this, $this->stat);
			return true;
		}
		return eio_fstat($this->fd, $pri, function ($file, $stat) use ($cb) {
			$stat       = FileSystem::statPrepare($stat);
			$file->stat = $stat;
			call_user_func($cb, $file, $stat);
		}, $this);
	}

	/**
	 * Stat() non-cached
	 * @param  callable $cb  Callback
	 * @param  integer  $pri Priority
	 * @return resource|boolean
	 */
	public function statRefresh($cb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!$this->fd || $this->fd === -1) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FileSystem::$supported) {
			call_user_func($cb, $this, FileSystem::statPrepare(fstat($this->fd)));
			return true;
		}
		return eio_fstat($this->fd, $pri, function ($file, $stat) use ($cb) {
			$stat       = FileSystem::statPrepare($stat);
			$file->stat = $stat;
			call_user_func($cb, $file, $stat);
		}, $this);
	}

	/**
	 * Statvfs()
	 * @param  callable $cb  Callback
	 * @param  integer  $pri Priority
	 * @return resource|boolean
	 */
	public function statvfs($cb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FileSystem::$supported) {
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
	 * @param  callable $cb  Callback
	 * @param  integer  $pri Priority
	 * @return resource|false
	 */
	public function sync($cb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FileSystem::$supported) {
			call_user_func($cb, $this, true);
			return false;
		}
		return eio_fsync($this->fd, $pri, $cb, $this);
	}

	/**
	 * Datasync()
	 * @param  callable $cb  Callback
	 * @param  integer  $pri Priority
	 * @return resource|false
	 */
	public function datasync($cb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FileSystem::$supported) {
			call_user_func($cb, $this, true);
			return false;
		}
		return eio_fdatasync($this->fd, $pri, $cb, $this);
	}

	/**
	 * Writes data to file
	 * @param  string   $data   Data
	 * @param  callable $cb     Callback
	 * @param  integer  $offset Offset
	 * @param  integer  $pri    Priority
	 * @return resource|false
	 */
	public function write($data, $cb = null, $offset = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if ($data === '') {
			if ($cb) {
				call_user_func($cb, $this, 0);
			}
			return false;
		}
		if (!FileSystem::$supported) {
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
		$res           = eio_write($this->fd, $data, $l, $offset, $pri, function ($file, $result) {
			$this->writing = false;
			$this->onWriteOnce->executeAll($file, $result);
		}, $this);
		return $res;
	}

	/**
	 * Changes ownership of this file
	 * @param  integer  $uid User ID
	 * @param  integer  $gid Group ID
	 * @param  callable $cb  Callback
	 * @param  integer  $pri Priority
	 * @return resource|false
	 */
	public function chown($uid, $gid = -1, $cb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FileSystem::$supported) {
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
	 * @param  integer  $mtime Last modification time
	 * @param  integer  $atime Last access time
	 * @param  callable $cb    Callback
	 * @param  integer  $pri   Priority
	 * @return resource|false
	 */
	public function touch($mtime, $atime = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FileSystem::$supported) {
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
		$this->stat    = null;
		$this->statvfs = null;
	}

	/**
	 * Reads data from file
	 * @param  integer  $length Length
	 * @param  integer  $offset Offset
	 * @param  callable $cb     Callback
	 * @param  integer  $pri    Priority
	 * @return boolean
	 */
	public function read($length, $offset = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FileSystem::$supported) {
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
			$cb,
			$this
		);
		return true;
	}

	/**
	 * sendfile()
	 * @param  mixed    $outfd   File descriptor
	 * @param  callable $cb      Callback
	 * @param  callable $startCb Start callback
	 * @param  integer  $offset  Offset
	 * @param  integer  $length  Length
	 * @param  integer  $pri     Priority
	 * @return boolean           Success
	 */
	public function sendfile($outfd, $cb, $startCb = null, $offset = 0, $length = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FileSystem::$supported) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		static $chunkSize = 1024;
		$ret     = true;
		$handler = function ($file, $sent = -1) use (&$ret, $outfd, $cb, &$handler, &$offset, &$length, $pri, $chunkSize) {
			if ($outfd instanceof IOStream) {
				if ($outfd->isFreed()) {
					call_user_func($cb, $file, false);
					return;
				}
				$ofd = $outfd->getFd();
			}
			else {
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
			$c   = min($chunkSize, $length);
			$ret = eio_sendfile($ofd, $file->fd, $offset, $c, $pri, $handler, $file);
		};
		if ($length !== null) {
			if ($startCb !== null) {
				if (!call_user_func($startCb, $this, $length, $handler)) {
					$handler($this);
				}
			}
			else {
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
			}
			else {
				$handler($file);
			}
		}, $pri);
		return true;
	}

	/**
	 * readahead()
	 * @param  integer  $length Length
	 * @param  integer  $offset Offset
	 * @param  callable $cb     Callback
	 * @param  integer  $pri    Priority
	 * @return resource|false
	 */
	public function readahead($length, $offset = null, $cb = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!$this->fd) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		if (!FileSystem::$supported) {
			if ($cb) {
				call_user_func($cb, $this, false);
			}
			return false;
		}
		$this->offset += $length;
		return eio_readahead(
			$this->fd,
			$length,
			$offset !== null ? $offset : $this->offset,
			$pri,
			$cb,
			$this
		);
	}

	/**
	 * Generates closure-callback for readAll
	 * @param  callable $cb
	 * @param  integer  $size
	 * @param  integer  &$offset
	 * @param  integer  &$pri
	 * @param  string   &$buf
	 * @return callable
	 */
	protected function readAllGenHandler($cb, $size, &$offset, &$pri, &$buf) {
		return function ($file, $data) use ($cb, $size, &$offset, &$pri, &$buf) {
			$buf .= $data;
			$offset += strlen($data);
			$len = min($file->chunkSize, $size - $offset);
			if ($offset >= $size) {
				if ($cb) {
					call_user_func($cb, $file, $buf);
				}
				return;
			}
			eio_read($file->fd, $len, $offset, $pri, $this->readAllGenHandler($cb, $size, $offset, $pri, $buf), $this);
		};
	}

	/**
	 * Reads whole file
	 * @param  callable $cb  Callback
	 * @param  integer  $pri Priority
	 * @return boolean       Success
	 */
	public function readAll($cb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
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
			$buf    = '';
			$size   = $stat['size'];
			eio_read($file->fd, min($file->chunkSize, $size), 0, $pri, $this->readAllGenHandler($cb, $size, $offset, $pri, $buf), $file);
		}, $pri);
		return true;
	}

	/**
	 * Generates closure-callback for readAllChunked
	 * @param  callable $cb
	 * @param  callable $chunkcb
	 * @param  integer  $size
	 * @param  integer  $offset
	 * @param  integer  $pri
	 * @return callable
	 */
	protected function readAllChunkedGenHandler($cb, $chunkcb, $size, &$offset, $pri) {
		return function ($file, $data) use ($cb, $chunkcb, $size, &$offset, $pri) {
			call_user_func($chunkcb, $file, $data);
			$offset += strlen($data);
			$len = min($file->chunkSize, $size - $offset);
			if ($offset >= $size) {
				call_user_func($cb, $file, true);
				return;
			}
			eio_read($file->fd, $len, $offset, $pri, $this->readAllChunkedGenHandler($cb, $chunkcb, $size, $offset, $pri), $file);
		};
	}

	/**
	 * Reads file chunk-by-chunk
	 * @param  callable $cb      Callback
	 * @param  callable $chunkcb Callback of chunk
	 * @param  integer  $pri     Priority
	 * @return resource|false
	 */
	public function readAllChunked($cb = null, $chunkcb = null, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
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
			$size   = $stat['size'];
			eio_read($file->fd, min($file->chunkSize, $size), $offset, $pri, $this->readAllChunkedGenHandler($cb, $chunkcb, $size, $offset, $pri), $file);
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
	 * @param  integer $n Chunk size
	 * @return void
	 */
	public function setChunkSize($n) {
		$this->chunkSize = $n;
	}

	/**
	 * Move pointer to arbitrary position
	 * @param  integer  $offset Offset
	 * @param  callable $cb     Callback
	 * @param  integer  $pri    Priority
	 * @return resource|false
	 */
	public function seek($offset, $cb, $pri = EIO_PRI_DEFAULT) {
		$cb = CallbackWrapper::forceWrap($cb);
		if (!\EIO::$supported) {
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
		if (\EIO::$supported) {
			return $this->offset;
		}
		return ftell($this->fd);
	}

	/**
	 * Close the file
	 * @return resource|false
	 */
	public function close() {
		if ($this->closed) {
			return false;
		}
		$this->closed = true;
		if ($this->fdCacheKey !== null) {
			FileSystem::$fdCache->invalidate($this->fdCacheKey);
		}
		if ($this->fd === null) {
			return false;
		}

		if (!FileSystem::$supported) {
			fclose($this->fd);
			return false;
		}
		$r        = eio_close($this->fd, EIO_PRI_MAX);
		$this->fd = null;
		return $r;
	}

	/**
	 * Destructor
	 */
	public function __destruct() {
		$this->close();
	}
}
