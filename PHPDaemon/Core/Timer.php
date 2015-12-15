<?php
namespace PHPDaemon\Core;

use PHPDaemon\Core\Daemon;

/**
 * Timed event
 * @package PHPDaemon\Core
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class Timer {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var integer|null Timer id
	 */
	public $id;
	/**
	 * @var \EventBufferEvent Event resource
	 */
	protected $ev;
	/**
	 * @var integer Current timeout holder
	 */
	public $lastTimeout;
	/**
	 * @var boolean Is the timer finished?
	 */
	public $finished = false;
	/**
	 * @var callable Callback
	 */
	public $cb;
	/**
	 * @var Timer[] List of timers
	 */
	protected static $list = [];
	/**
	 * @var integer Priority
	 */
	public $priority;
	/**
	 * @var integer Counter
	 */
	protected static $counter = 1;

	/**
	 * Constructor
	 * @param  callable       $cb       Callback
	 * @param  integer        $timeout  Timeout
	 * @param  integer|string $id       Timer ID
	 * @param  integer        $priority Priority
	 */
	public function __construct($cb, $timeout = null, $id = null, $priority = null) {
		if ($id === null) {
			$id = ++self::$counter;
		}
		else {
			$id = (string) $id;
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
	 * @param  integer $priority Priority
	 * @return void
	 */
	public function setPriority($priority) {
		$this->priority     = $priority;
		$this->ev->priority = $priority;
	}

	/**
	 * Adds timer
	 * @param  callable       $cb       Callback
	 * @param  integer        $timeout  Timeout
	 * @param  integer|string $id       Timer ID
	 * @param  integer        $priority Priority
	 * @return integer|string           Timer ID
	 */
	public static function add($cb, $timeout = null, $id = null, $priority = null) {
		$obj = new self($cb, $timeout, $id, $priority);
		return $obj->id;
	}

	/**
	 * Sets timeout
	 * @param  integer|string $id       Timer ID
	 * @param  integer        $timeout  Timeout
	 * @return boolean
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
	 * @param  integer|string $id Timer ID
	 * @return void
	 */
	public static function remove($id) {
		if (isset(self::$list[$id])) {
			self::$list[$id]->free();
		}
	}

	/**
	 * Cancels timer by ID
	 * @param  integer|string $id Timer ID
	 * @return void
	 */
	public static function cancelTimeout($id) {
		if (isset(self::$list[$id])) {
			self::$list[$id]->cancel();
		}
	}

	/**
	 * Sets timeout
	 * @param  integer $timeout Timeout
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
	 * @return void
	 */
	public function free() {
		unset(self::$list[$this->id]);
		if ($this->ev !== null) {
			$this->ev->free();
			$this->ev = null;
		}
	}
}
