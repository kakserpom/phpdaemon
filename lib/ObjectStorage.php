<?php
class ObjectStorage extends SplObjectStorage {
	public function each() {
		if ($this->count() === 0) {
			return 0;
		}
		$args = func_get_args();
		$method = array_shift($args);
		$n = 0;
		foreach ($this as $obj) {
			call_user_func_array(array($obj, $method), $args);
			++$n;
		}
		return $n;
	}
	public function removeAll($obj = null) {
		if ($obj === null) {
			$this->removeAllExcept(new SplObjectStorage);
		}
		parent::removeAll($obj);
	}
	public function detachFirst() {
		$this->rewind();
		$o = $this->current();
		if (!$o) {
			return false;
		}
		$this->detach($o);
		return $o;
	}
	public function getFirst() {
		$this->rewind();
		return $this->current();
	}
}
