<?php
namespace PHPDaemon\Thread;

/**
 * Collection of threads
 *
 * @package Core
 *
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class Collection {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * Array of threads
	 * @var array|Generic[]
	 */
	public $threads = [];

	/**
	 * Counter of spawned threads
	 * @var int
	 */
	protected $spawnCounter = 0;

	/**
	 * Pushes certain thread to the collection
	 * @param object Generic to push
	 * @return void
	 */
	public function push($thread) {
		$id = ++$this->spawnCounter;
		$thread->setId($id);
		$this->threads[$id] = $thread;
	}

	/**
	 * Start all collected threads
	 * @return void
	 */
	public function start() {
		foreach ($this->threads as $thread) {
			$thread->start();
		}
	}

	/**
	 * Stop all collected threads
	 * @param boolean Kill?
	 * @return void
	 */
	public function stop($kill = false) {
		foreach ($this->threads as $thread) {
			$thread->stop($kill);
		}
	}

	/**
	 * Return the collected threads count
	 * @return integer Count
	 */
	public function getCount() {
		return sizeof($this->threads);
	}

	/**
	 * Remove terminated threads from the collection
	 * @return integer Rest threads count
	 */
	public function removeTerminated() { // @TODO: remove
		$n = 0;
		foreach ($this->threads as $id => $thread) {
			if (!$thread->getPid() || !$thread->ifExists()) {
				$thread->setTerminated();
				unset($this->threads[$id]);
				continue;
			}
			++$n;
		}
		return $n;
	}

	/**
	 * Send a signal to threads
	 * @param integer Signal's number
	 * @return void
	 */
	public function signal($sig) {
		foreach ($this->threads as $thread) {
			$thread->signal($sig);
		}
	}
}
