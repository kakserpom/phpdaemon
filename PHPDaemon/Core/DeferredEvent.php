<?php
namespace PHPDaemon\Core;

use PHPDaemon\Structures\StackCallbacks;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/**
 * DeferredEvent class.
 */
class DeferredEvent {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * State: waiting. It means there are no listeners yet.
	 * @var integer
	 */
	const STATE_WAITING = 1;
	
	/**
	 * State: running. Event handler in progress.
	 * @var integer
	 */
	const STATE_RUNNING = 2;
	
	/**
	 * State: done. Event handler is finished, result is saved.
	 * @var integer
	 */
	const STATE_DONE = 3;

	/**
	 * Stack of listeners
	 * @var object \PHPDaemon\Structures\StackCallbacks
	 */
	protected $listeners;

	/**
	 * Result of deferred event
	 * @var mixed
	 */
	protected $result;
	
	/**
	 * State of event. One of STATE_*
	 * @var int
	 */
	protected $state;

	/**
	 * Arguments which passed to __invoke
	 * @var array
	 */
	protected $args;

	/**
	 * Event handler (producer)
	 * @var callable
	 */
	protected $producer;

	/**
	 * Parent object
	 * @var object
	 */
	public $parent;

	/**
	 * Name of event
	 * @var string
	 */
	public $name;

	/**
	 * Constructor
	 * @param $cb
	 * @return \DeferredEvent
	 */
	public function __construct($cb) {
		$this->state     = self::STATE_WAITING;
		$this->producer     = $cb;
		$this->listeners = new StackCallbacks;
	}

	/**
	 * Set producer callback
	 * @param callable $cb
	 * @return void
	 */
	public function setProducer($cb) {
		$this->producer = $cb;
	}

	/**
	 * Set result
	 * @param mixed $result
	 * @return void
	 */
	public function setResult($result = null) {
		$this->result = $result;
		$this->state  = self::STATE_DONE;
		if ($this->listeners) {
			$this->listeners->executeAll($this->result);
		}
	}

	/**
	 * Clean up
	 * @return void
	 */
	public function cleanup() {
		$this->listeners = null;
		$this->producer  = null;
		$this->args      = [];
	}

	/**
	 * Reset
	 * @return $this
	 */
	public function reset() {
		$this->state = self::STATE_WAITING;
		$this->result = null;
		return $this;
	}

	/**
	 * Add listener
	 * @param callable $cb
	 * @return void
	 */
	public function addListener($cb) {
		if ($this->state === self::STATE_DONE) {
			call_user_func($cb, $this);
			return;
		}
		$this->listeners->push($cb);
		if ($this->state === self::STATE_WAITING) {
			$i = 1;
			$n = func_num_args();
			while ($i < $n) {
				$this->args[] = func_get_arg($i);
				++$i;
			}
			$this->state = self::STATE_RUNNING;
			call_user_func($this->producer, $this);
		}
	}

	/**
	 * Called when object is invoked as function.
	 * @param callable $cb
	 * @param array $params
	 * @return void
	 */
	public function __invoke($cb, $params = []) {
		$this->addListener($cb, $params);
	}
}
