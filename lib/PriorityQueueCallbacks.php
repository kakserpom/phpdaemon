<?php
class PriorityQueueCallbacks extends SplPriorityQueue {
	public function insert($cb, $pri = 0) {
		parent::insert(CallbackWrapper::wrap($cb), $pri);
	}
	public function enqueue($cb, $pri = 0) {
		return parent::insert(CallbackWrapper::wrap($cb), $pri);
	}
	public function dequeue() {
		return $this->extract();
	}
	public function compare($pri1, $pri2)  { 
		if ($pri1 === $pri2) {
			return 0;
		}
		return $pri1 < $pri2 ? -1 : 1; 
    } 
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
	public function executeAll() {
		if ($this->isEmpty()) {
			return 0;
		}
		$args = func_get_args();
		$n = 0;
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

