<?php
namespace PHPDaemon\Utils;

use PHPDaemon\Core\Daemon;

class ShmEntity {
	/**
	 * Path
	 * @var string
	 */
	protected $path;

	/**
	 * Segments
	 * @var array
	 */
	protected $segments = [];

	/**
	 * Segment size
	 * @var integer
	 */
	protected $segsize = 1024;

	/**
	 * Name
	 * @var string
	 */
	protected $name;

	/**
	 * Key
	 * @var integer
	 */
	protected $key;

	/**
	 * @param $path
	 * @param $segsize
	 * @param $name
	 * @param bool $create
	 */
	public function __construct($path, $segsize, $name, $create = false) {
		$this->path    = $path;
		$this->segsize = $segsize;
		$this->name    = $name;
		if ($create && !touch($this->path)) {
			Daemon::log('Couldn\'t touch IPC file \'' . $this->path . '\'.');
			exit(0);
		}

		if (($this->key = ftok($this->path, 't')) === false) {
			Daemon::log('Couldn\'t ftok() IPC file \'' . $this->path . '\'.');
			exit(0);
		}
		if (!$this->open(0, $create) && $create) {
			Daemon::log('Couldn\'t open IPC-' . $this->name . '  shared memory segment (key=' . $this->key . ', segsize=' . $this->segsize . ', uid=' . posix_getuid() . ', path = ' . $this->path . ').');
			exit(0);
		}
	}

	/**
	 * Opens segment of shared memory.
	 * @return int Segment number.
	 */
	public function open($segno = 0, $create = false) {
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
	 * @param string  Data
	 * @param integer Offset
	 * @return void
	 */
	public function write($data, $offset) { // @TODO: prevent overflow
		$segno = floor($offset / $this->segsize);
		if (!isset($this->segments[$segno])) {
			$this->open($segno, true);
		}
		shmop_write($this->segments[$segno], $data, $offset % $this->segsize);
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

