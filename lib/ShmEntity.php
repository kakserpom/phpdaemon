<?php
class ShmEntity {
	public $path;
	public $segments = array();
	public $segsize = 1024;
	public $name;
	public $key;

	public function __construct($path, $segsize, $name) {
		$this->path = $path;
		$this->segsize = $segsize;
		$this->name = $name;
		$create = true;
		if ($create	&& !touch($this->path)) {
			Daemon::log('Couldn\'t touch IPC file \'' . $this->path . '\'.');
			exit(0);
		}

		if (($this->key = ftok($this->path,'t')) === false) {
			Daemon::log('Couldn\'t ftok() IPC file \'' . $this->path . '\'.');
			exit(0);
		}

		if (!$this->open()) {
			Daemon::log('Couldn\'t open IPC-'.$this->name.'  shared memory segment (key=' . $key . ', segsize=' . $this->segsize . ', uid=' . posix_getuid() . ', path = '.$this->path.').');
			exit(0);
		}
	}
	/**
	 * Opens segment of shared memory.
	 * @return int Segment number.
	 */
	public function open($segno = 0, $create = true) {
		$key = $this->key + $segno;
		if (!$create) {
			$shm = @shmop_open($key, 'w', 0, 0);
		} else {
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

	public function openall() {
		do {
			$r = $this->open(sizeof($this->segments), false);
		} while ($r);
	}

	public function write($data, $offset) {
		$segno = floor($offset / $this->segsize);
		if (!isset($this->segments[$segno])) {
			$this->open($segno, true);
		}
		shmop_write($this->segments[$segno], $data, $offset % $this->segsize);
	}

	public function delete() {
		foreach ($this->segments as $shm) {
			shmop_delete($shm);
		}
	}
}

