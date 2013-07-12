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
	 * Constructor
	 * @param callable $cb
	 * @param int $timeout
	 * @param int|string $id
	 * @param int $priority
	 * @return \PHPDaemon\Core\Timer
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
	 * Called when timer is triggered
	 * @param mixed $arg
	 * @return void
	 */
	public function eventCall() {
		try {
			//Daemon::log('cb - '.Debug::zdump($this->cb));
			call_user_func($this->cb, $this);
		} catch (\Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
	}

	/**
	 * Set prioriry
	 * @param $priority
	 * @return void
	 */
	public function setPriority($priority) {
		$this->priority     = $priority;
		$this->ev->priority = $priority;
	}

	/**
	 * Adds timer
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
	 * Sets timeout
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
	 * Removes timer by ID
	 * @param $id
	 * @return void
	 */
	public static function remove($id) {
		if (isset(self::$list[$id])) {
			self::$list[$id]->free();
		}
	}

	/**
	 * Cancels timer by ID
	 * @param $id
	 * @return void
	 */
	public static function cancelTimeout($id) {
		if (isset(self::$list[$id])) {
			self::$list[$id]->cancel();
		}
	}

	/**
	 * Sets timeout
	 * @param int $timeout
	 * @return void
	 */
	public function timeout($timeout = null) {
		if ($timeout !== null) {
			$this->lastTimeout = $timeout;
		}
		$this->ev->add($this->lastTimeout / 1e6);
	}

	/**
	 * Cancels timer
	 * @return void
	 */
	public function cancel() {
		$this->ev->del();
	}

	/**
	 * Finishes timer
	 * @return void
	 */
	public function finish() {
		$this->free();
	}

	/**
	 * Destructor
	 * @return void
	 */
	public function __destruct() {
		$this->free();
	}

	/**
	 * Frees the timer
	 */
	public function free() {
		unset(self::$list[$this->id]);
		if ($this->ev !== null) {
			$this->ev->free();
			$this->ev = null;
		}
	}
}
