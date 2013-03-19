<?php
class StackCallbacks extends SplStack {
	/**
	 * Push callback to the bottom of stack
	 * @param callable Callback
	 * @return void
	 */
	public function push($cb) {
		parent::push(CallbackWrapper::wrap($cb));
	}

	/* Push callback to the top of stack
	 * @param callable Callback
	 * @return void
	 */
	public function unshift($cb) {
		parent::unshift(CallbackWrapper::wrap($cb));
	}

	/* Executes one callback from the top with given arguments.
	 * @return void
	 */
	public function executeOne() {
		if ($this->isEmpty()) {
			return false;
		}
		$cb = $this->shift();
		if ($cb) {
			call_user_func_array($cb, func_get_args());
		}
		return true;
	}

	/* Executes all callbacks with given arguments.
	 * @return void
	 */
	public function executeAll() {
		if ($this->isEmpty()) {
			return 0;
		}
		$args = func_get_args();
		$n = 0;
		do {
			$cb = $this->shift();
			if ($cb) {
				call_user_func_array($cb, $args);
				++$n;
			}
		} while (!$this->isEmpty());
		return $n;
	}
}
