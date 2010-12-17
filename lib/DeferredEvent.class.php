<?php

/**
 * DeferredEvent class.
 */
class DeferredEvent {

	const STATE_WAITING = 1;
	const STATE_RUNNING = 2;
	const STATE_DONE = 3;
	
	public $listeners = array();
	public $result;
	public $state;
	public $args;
	public $onRun;

	public function __construct($cb) {
		$this->state = self::STATE_WAITING;
		$this->onRun = $cb;
	}
	
	public function setProducer($cb) {
		$this->onRun = $cb;
	}
	
	public function setResult($result = NULL) {
		$this->result = $result;
		$this->state = self::STATE_DONE;
		while ($cb = array_pop($this->listeners)) {
			$cb($this->result);
		}
	}
	
	public function cleanup() {
		$this->listeners = array();
		$this->onRun = NULL;
		$this->args = array();
	}
	
	public function addListener($cb) {
		if ($this->state === self::STATE_DONE) {
			$cb($this);
			return;
		}
		$this->listeners[] = $cb;
		if ($this->state === self::STATE_WAITING) {
			$i = 1;
			$n = func_num_args();
			while ($i < $n) {
				$this->args[] = func_get_arg($i);
				++$i;
			}
			$this->state = self::STATE_RUNNING;
			call_user_func($this->onRun,$this);
		}
	}
	
	public function __invoke($cb, $params = array()) {
		$this->addListener($cb, $params);
	}
}

