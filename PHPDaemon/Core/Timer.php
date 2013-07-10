<?php
namespace PHPDaemon\Core;

use PHPDaemon\Core\Daemon;

/**
 * Timed event
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class Timer {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var int|null
	 */
	public $id; // timer id
	/**
	 * @var \EventBufferEvent
	 */
	public $ev; // event resource
	/**
	 * @var
	 */
	public $lastTimeout; // Current timeout holder
	/**
	 * @var bool
	 */
	public $finished = false; // Is the timer finished?
	/**
	 * @var callable
	 */
	public $cb; // callback
	/**
	 * @var Timer[]
	 */
	public static $list = []; // list of timers
	/**
	 * @var int
	 */
	public $priority;
	/**
	 * @var int
	 */
	static $counter = 0;

	/**
	 * @TODO DESCR
	 * @param callable $cb
	 * @param int $timeout
	 * @param int|string $id
	 * @param int $priority
	 */
	public function __construct($cb, $timeout = null, $id = null, $priority = null) {
		if ($id === null) {
			$id = ++self::$counter;
		}
		$this->id = $id;
		$this->cb = $cb;
		$this->ev = \Event::timer(Daemon::$process->eventBase, [$this, 'eventCall']);
		if ($priority !== null) {
			$this->setPriority($priority);
		}
		if ($timeout !== null) {
			$this->timeout($timeout);
		}
		self::$list[$id] = $this;
	}

	/**
	 * @TODO DESCR
	 * @param $arg
	 */
	public function eventCall($arg) {
		try {
			//Daemon::log('cb - '.Debug::zdump($this->cb));
			call_user_func($this->cb, $this);
		} catch (\Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
	}

	/**
	 * @TODO DESCR
	 * @param $priority
	 */
	public function setPriority($priority) {
		$this->priority     = $priority;
		$this->ev->priority = $priority;
	}

	/**
	 * @TODO DESCR
	 * @param callable $cb
	 * @param int $timeout
	 * @param int|string $id
	 * @param int $priority
	 * @return int|null
	 */
	public static function add($cb, $timeout = null, $id = null, $priority = null) {
		$obj = new self($cb, $timeout, $id, $priority);
		return $obj->id;
	}

	/**
	 * @TODO DESCR
	 * @param int|string $id
	 * @param int $timeout
	 * @return bool
	 */
	public static function setTimeout($id, $timeout = NULL) {
		if (isset(self::$list[$id])) {
			self::$list[$id]->timeout($timeout);
			return true;
		}
		return false;
	}

	/**
	 * @TODO DESCR
	 * @param $id
	 */
	public static function remove($id) {
		if (isset(self::$list[$id])) {
			self::$list[$id]->free();
		}
	}

	/**
	 * @TODO DESCR
	 * @param $id
	 */
	public static function cancelTimeout($id) {
		if (isset(self::$list[$id])) {
			self::$list[$id]->cancel();
		}
	}

	/**
	 * @TODO DESCR
	 * @param int $timeout
	 */
	public function timeout($timeout = null) {
		if ($timeout !== null) {
			$this->lastTimeout = $timeout;
		}
		$this->ev->add($this->lastTimeout / 1e6);
	}

	/**
	 * @TODO DESCR
	 */
	public function cancel() {
		$this->ev->del();
	}

	/**
	 * @TODO DESCR
	 */
	public function finish() {
		$this->free();
	}

	/**
	 * @TODO DESCR
	 */
	public function __destruct() {
		$this->free();
	}

	/**
	 * @TODO DESCR
	 */
	public function free() {
		unset(self::$list[$this->id]);
		if ($this->ev !== null) {
			$this->ev->free();
			$this->ev = null;
		}
	}
}
