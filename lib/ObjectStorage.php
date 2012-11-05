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
}
