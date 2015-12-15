<?php
namespace PHPDaemon\Structures;

use PHPDaemon\Core\CallbackWrapper;

/**
 * StackCallbacks
 * @package PHPDaemon\Structures
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class StackCallbacks extends \SplStack {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * Push callback to the bottom of stack
	 * @param  callable $cb Callback
	 * @return void
	 */
	public function push($cb) {
		parent::push(CallbackWrapper::wrap($cb));
	}

	/**
	 * Push callback to the top of stack
	 * @param  callable $cb Callback
	 * @return void
	 */
	public function unshift($cb) {
		parent::unshift(CallbackWrapper::wrap($cb));
	}

	/**
	 * Executes one callback from the top with given arguments
	 * @param  mixed   ...$args Arguments
	 * @return boolean
	 */
	public function executeOne() {
		if ($this->isEmpty()) {
			return false;
		}
		$cb = $this->shift();
		if ($cb) {
			call_user_func_array($cb, func_get_args());
			if ($cb instanceof CallbackWrapper) {
				$cb->cancel();
			}
		}
		return true;
	}

	/**
	 * Executes one callback from the top with given arguments without taking it out
	 * @param  mixed   ...$args Arguments
	 * @return boolean
	 */
	public function executeAndKeepOne() {
		if ($this->isEmpty()) {
			return false;
		}
		$cb = $this->shift();
		$this->unshift($cb);
		if ($cb) {
			call_user_func_array($cb, func_get_args());
		}
		return true;
	}

	/**
	 * Executes all callbacks with given arguments
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
			$cb = $this->shift();
			if ($cb) {
				call_user_func_array($cb, $args);
				++$n;
				if ($cb instanceof CallbackWrapper) {
					$cb->cancel();
				}
			}
		} while (!$this->isEmpty());
		return $n;
	}

	/**
	 * Return array
	 * @return array
	 */
	public function toArray() {
		$arr = [];
		while (!$this->isEmpty()) {
			$arr[] = $this->shift();
		}
		return $arr;
	}

	/**
	 * Shifts all callbacks sequentially
	 * @return void
	 */
	public function reset() {
		while (!$this->isEmpty()) {
			$this->shift();
		}
	}
}
