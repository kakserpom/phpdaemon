<?php
namespace PHPDaemon\Utils;

use PHPDaemon\Core\Daemon;

/**
 * ShmEntity
 * @package PHPDaemon\Utils
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class ShmEntity {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var string Path
	 */
	protected $path;

	/**
	 * @var array Segments
	 */
	protected $segments = [];

	/**
	 * @var integer Segment size
	 */
	protected $segsize = 1024;

	/**
	 * @var string Name
	 */
	protected $name;

	/**
	 * @var integer Key
	 */
	protected $key;

	/**
	 * Constructor
	 * @param string  $path    Path
	 * @param integer $segsize Segment size
	 * @param string  $name    Name
	 * @param boolean $create  Create
	 */
	public function __construct($path, $segsize, $name, $create = false) {
		$this->path    = $path;
		$this->segsize = $segsize;
		$this->name    = $name;
		if ($create && !touch($this->path)) {
			Daemon::log('Couldn\'t touch IPC file \'' . $this->path . '\'.');
			exit(0);
		}

		if (!is_file($this->path) || ($this->key = ftok($this->path, 't')) === false) {
			Daemon::log('Couldn\'t ftok() IPC file \'' . $this->path . '\'.');
			exit(0);
		}
		if (!$this->open(0, $create) && $create) {
			Daemon::log('Couldn\'t open IPC-' . $this->name . '  shared memory segment (key=' . $this->key . ', segsize=' . $this->segsize . ', uid=' . posix_getuid() . ', path = ' . $this->path . ').');
			exit(0);
		}
	}

	/**
	 * Opens segment of shared memory
	 * @param  integer $segno  Segment number
	 * @param  boolean $create Create
	 * @return integer         Segment number
	 */
	public function open($segno = 0, $create = false) {
		if (isset($this->segments[$segno])) {
			return $this->segments[$segno];
		}
		$key = $this->key + $segno;
		if (!$create) {
			$shm = @shmop_open($key, 'w', 0, 0);
		}
		else {
			$shm = @shmop_open($key, 'w', 0, 0);

			if ($shm) {
				shmop_delete($shm);
				shmop_close($shm);
			}

			$shm = shmop_open($key, 'c', 0755, $this->segsize);
		}
		if (!$shm) {
			return false;
		}
		$this->segments[$segno] = $shm;
		return $shm;
	}

	/**
	 * Get open segments
	 * @return array
	 */
	public function getSegments() {
		return $this->segments;
	}

	/**
	 * Open all segments
	 * @return void
	 */
	public function openall() {
		do {
			$r = $this->open(sizeof($this->segments));
		} while ($r);
	}

	/**
	 * Write to shared memory
	 * @param  string  $data   Data
	 * @param  integer $offset Offset
	 * @return boolean         Success
	 */
	public function write($data, $offset) {
		$segno = floor($offset / $this->segsize);
		if (!isset($this->segments[$segno])) {
			if (!$this->open($segno, true)) {
				return false;
			}
		}
		$sOffset = $offset % $this->segsize;
		$d = $this->segsize - ($sOffset + strlen($data));
		if ($d < 0) {
			$this->write(binarySubstr($data, $d), ($segno+1) * $this->segsize);
			$data = binarySubstr($data, 0, $d);
		}
		//Daemon::log('writing to #'.$offset.' (segno '.$segno.')');
		shmop_write($this->segments[$segno], $data, $sOffset);
		return true;
	}

	/**
	 * Read from shared memory
	 * @param  integer $offset Offset
	 * @param  integer $length Length
	 * @return string          Data
	 */
	public function read($offset, $length = 1) {
		$ret = '';
		$segno = floor($offset / $this->segsize);
		$sOffset = $offset % $this->segsize;
		for (;;++$segno) {
			if (!isset($this->segments[$segno])) {
				if (!$this->open($segno)) {
					goto ret;
				}
			}
			$read = shmop_read($this->segments[$segno], $sOffset, min($length - strlen($ret), $this->segsize));
			//Daemon::log('read '.strlen($read).' from segno #'.$segno);
			$ret .= $read;
			if (strlen($ret) >= $length) {
				goto ret;
			}
			$sOffset = 0;
		}
		ret: 
		if ($ret === '') {
			return false;
		}
		return $ret;
	}

	/**
	 * Deletes all segments
	 * @return void
	 */
	public function delete() {
		foreach ($this->segments as $shm) {
			shmop_delete($shm);
		}
	}
}

