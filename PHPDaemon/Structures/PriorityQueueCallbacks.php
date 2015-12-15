<?php
namespace PHPDaemon\Structures;

use PHPDaemon\Core\CallbackWrapper;

/**
 * PriorityQueueCallbacks
 * @package PHPDaemon\Structures
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class PriorityQueueCallbacks extends \SplPriorityQueue {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * Insert callback
	 * @param  callable $cb  Callback
	 * @param  integer  $pri Priority
	 * @return void
	 */
	public function insert($cb, $pri = 0) {
		parent::insert(CallbackWrapper::wrap($cb), $pri);
	}

	/**
	 * Enqueue callback
	 * @param  callable $cb  Callback
	 * @param  integer  $pri Priority
	 * @return void
	 */
	public function enqueue($cb, $pri = 0) {
		parent::insert(CallbackWrapper::wrap($cb), $pri);
	}

	/**
	 * Dequeue
	 * @return callable
	 */
	public function dequeue() {
		return $this->extract();
	}

	/**
	 * Compare two priorities
	 * @param  integer $pri1
	 * @param  integer $pri2
	 * @return integer
	 */
	public function compare($pri1, $pri2) {
		if ($pri1 === $pri2) {
			return 0;
		}
		return $pri1 < $pri2 ? -1 : 1;
	}

	/**
	 * Executes one callback from the top of queue with arbitrary arguments
	 * @param  mixed   ...$args Arguments
	 * @return boolean
	 */
	public function executeOne() {
		if ($this->isEmpty()) {
			return false;
		}
		$cb = $this->extract();
		if ($cb) {
			call_user_func_array($cb, func_get_args());
		}
		return true;
	}

	/**
	 * Executes all callbacks from the top of queue to bottom with arbitrary arguments
	 * @param  mixed   ...$args Arguments
	 * @return integer
	 */
	public function executeAll() {
		if ($this->isEmpty()) {
			return 0;
		}
		$args = func_get_args();
		$n    = 0;
		do {
			$cb = $this->extract();
			if ($cb) {
				call_user_func_array($cb, $args);
				++$n;
			}
		} while (!$this->isEmpty());
		return $n;
	}
}

