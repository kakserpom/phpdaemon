<?php

/**
 * ComplexJob class.
 */
class ComplexJob {

	const STATE_WAITING = 1;
	const STATE_RUNNING = 2;
	const STATE_DONE = 3;
	
	public $listeners = array();
	public $results = array();
	public $state;
	public $jobs = array();

	public function __construct($cb) {
		$this->state = self::STATE_WAITING;
		$this->addListener($cb);
	}
		
	public function setResult($jobname, $result = null) {
		$this->results[$jobname] = $result;
		if (sizeof($this->results) >= sizeof($this->jobs)) {
			$this->jobs = array();
			$this->state = self::STATE_DONE;
			while ($cb = array_pop($this->listeners)) {
				$cb($this);
			}
		}
	}
	
	public function addJob($jobname, $cb) {
		$this->jobs[$jobname] = $cb;
	}
	
	public function cleanup() {
		$this->listeners = array();
		$this->results = array();
		$this->jobs = array();
	}
	
	public function addListener($cb) {
		$this->listeners[] = $cb;
	}
	
	public function __invoke($name = null, $cb = null) {
		if (func_num_args() === 0) {
			$this->state = self::STATE_RUNNING;
			foreach ($this->jobs as $name => $job) {
				$job ($name, $this);
			}
			return;
		}
		$this->addJob($name, $cb);
	}
	
}

