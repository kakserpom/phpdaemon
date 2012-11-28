<?php
class StackCallbacks extends SplStack {
	public function push($cb) {
		parent::push(CallbackWrapper::wrap($cb));
	}
	public function unshift($cb) {
		parent::unshift(CallbackWrapper::wrap($cb));
	}
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
