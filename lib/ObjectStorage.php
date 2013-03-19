<?php
class ObjectStorage extends SplObjectStorage {

	/**
	 * Call given method of all objects in storage
	 * @param string Method
	 * @param ... arguments ... 
	 * @return integer Number of called objects
	 */
	public function each() {
		if ($this->count() === 0) {
			return 0;
		}
		$args = func_get_args();
		$method = array_shift($args);
		$n = 0;
		foreach ($this as $obj) {
			call_user_func_array([$obj, $method], $args);
			++$n;
		}
		return $n;
	}

	/**
	 * Remove all objects from this storage, which contained in another storage
	 * @param SplObjectStorage
	 * @return void
	 */
	public function removeAll($obj = null) {
		if ($obj === null) {
			$this->removeAllExcept(new SplObjectStorage);
		}
		parent::removeAll($obj);
	}

	/**
	 * Detaches first object and returns it
	 * @return object
	 */
	public function detachFirst() {
		$this->rewind();
		$o = $this->current();
		if (!$o) {
			return false;
		}
		$this->detach($o);
		return $o;
	}

	/**
	 * Returns first object
	 * @return object
	 */
	public function getFirst() {
		$this->rewind();
		return $this->current();
	}
}
