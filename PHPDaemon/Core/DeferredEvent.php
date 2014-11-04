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
	 */
	const STATE_WAITING = 1;
	
	/**
	 * State: running. Event handler in progress.
	 */
	const STATE_RUNNING = 2;
	
	/**
	 * State: done. Event handler is finished, result is saved.
	 */
	const STATE_DONE = 3;

	/**
	 * @var \PHPDaemon\Structures\StackCallbacks Stack of listeners
	 */
	protected $listeners;

	/**
	 * @var mixed Result of deferred event
	 */
	protected $result;
	
	/**
	 * @var int State of event. One of STATE_*
	 */
	protected $state;

	/**
	 * @var array Arguments which passed to __invoke
	 */
	protected $args;

	/**
	 * @var callable Event handler (producer)
	 */
	protected $producer;

	/**
	 * @var object Parent object
	 */
	public $parent;

	/**
	 * @var string Name of event
	 */
	public $name;

	/**
	 * Constructor
	 * @param callable $cb Callback
	 * @return DeferredEvent
	 */
	public function __construct($cb) {
		$this->state     = self::STATE_WAITING;
		$this->producer     = $cb;
		$this->listeners = new StackCallbacks;
	}

	/**
	 * Set producer callback
	 * @param callable $cb Callback
	 * @return void
	 */
	public function setProducer($cb) {
		$this->producer = $cb;
	}

	/**
	 * Set result
	 * @param mixed $result Result
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
		$this->parent = null;
	}

	/**
	 * Reset
	 * @return this
	 */
	public function reset() {
		$this->state = self::STATE_WAITING;
		$this->result = null;
		$this->args = [];
		return $this;
	}

	/**
	 * Add listener
	 * @param  callable $cb      Callback
	 * @param  mixed    ...$args Arguments
	 * @return void
	 */
	public function addListener($cb) {
		if ($this->state === self::STATE_DONE) {
			if ($cb !== null) {
				call_user_func($cb, $this);
			}
			return;
		}
		if ($cb !== null) {
			$this->listeners->push($cb);
		}
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
	 * @param  mixed ...$args Arguments
	 * @return void
	 */
	public function __invoke() {
		call_user_func_array([$this, 'addListener'], func_get_args());
	}
}
