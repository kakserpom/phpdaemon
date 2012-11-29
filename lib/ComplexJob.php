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
	public $resultsNum = 0;
	public $jobsNum = 0;

	public function __construct($cb = null) {
		$this->state = self::STATE_WAITING;
		if($cb !== null) {
			$this->addListener($cb);
		}
	}
	public function hasCompleted() {
		return $this->state === self::STATE_DONE;
	}

	public function __call($name, $args) {
		return call_user_func_array($this->{$name}, $args);
	}

	public function setResult($jobname, $result = null) {
		$this->results[$jobname] = $result;
		++$this->resultsNum;
		$this->checkIfAllReady();
	}
	
	public function getResult($jobname) {
		return isset($this->results[$jobname]) ? $this->results[$jobname] : null;
	}
	
	public function checkIfAllReady() {
		if ($this->resultsNum >= $this->jobsNum) {
			$this->jobs = array();
			$this->state = self::STATE_DONE;
			while ($cb = array_pop($this->listeners)) {
				call_user_func($cb, $this);
			}
		}
	}
	
	public function addJob($name, $cb) {
		if (isset($this->jobs[$name])) {
			return false;
		}
		$this->jobs[$name] = CallbackWrapper::wrap($cb);
		++$this->jobsNum;
		if (($this->state === self::STATE_RUNNING) || ($this->state === self::STATE_DONE)) {
			call_user_func($cb, $name, $this);
		}
		return true;
	}
	
	public function cleanup() {
		$this->listeners = array();
		$this->results = array();
		$this->jobs = array();
	}
	
	public function addListener($cb) {
		if ($this->state === self::STATE_DONE) {
			call_user_func($cb, $this);
			return;
		}
		$this->listeners[] = CallbackWrapper::wrap($cb);
	}

	public function execute() {
		if ($this->state === self::STATE_WAITING) {
			$this->state = self::STATE_RUNNING;
			foreach ($this->jobs as $name => $cb) {
				call_user_func($cb, $name, $this);
				$this->jobs[$name] = null;
			}
			$this->checkIfAllReady();
		}
	}
	
	public function __invoke($name = null, $cb = null) {
		if (func_num_args() === 0) {
			return $this->execute();
		}
		$this->addJob($name, $cb);
	}
}
