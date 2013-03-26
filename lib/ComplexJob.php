<?php

/**
 * ComplexJob class.
 */
class ComplexJob {

	const STATE_WAITING = 1;
	const STATE_RUNNING = 2;
	const STATE_DONE = 3;

	/**
	 * Listeners
	 * @var array [callable, ...]
	 */	
	public $listeners = [];

	/**
	 * Hash of results
	 * @var array [jobname -> result, ...]
	 */	
	public $results = [];

	/**
	 * Current state
	 * @var enum
	 */	
	public $state;

	/**
	 * Hash of results
	 * @var array [jobname -> result, ...]
	 */	
	public $jobs = [];

	/**
	 * Number of results
	 * @var integer
	 */	
	public $resultsNum = 0;

	/**
	 * Number of jobs
	 * @var integer
	 */	
	public $jobsNum = 0;

	/**
	 * Constructor
	 * @param callable Listener
	 * @return object
	 */	
	public function __construct($cb = null) {
		$this->state = self::STATE_WAITING;
		if($cb !== null) {
			$this->addListener($cb);
		}
	}

	/**
	 * Has completed?
	 * @return boolean
	 */	
	public function hasCompleted() {
		return $this->state === self::STATE_DONE;
	}

	/**
	 * Proxy call
	 * @param string Name
	 * @param array Arguments
	 * @return boolean
	 */	
	public function __call($name, $args) {
		if (!isset($this->{$name})) {
			return false;
		}
		return call_user_func_array($this->{$name}, $args);
	}

	/**
	 * Set result
	 * @param string Job name
	 * @param mixed Result
	 * @return void
	 */
	public function setResult($jobname, $result = null) {
		$this->results[$jobname] = $result;
		++$this->resultsNum;
		$this->checkIfAllReady();
	}
	
	/**
	 * Get result
	 * @param string Job name
	 * @return mixed Result or null
	 */
	public function getResult($jobname) {
		return isset($this->results[$jobname]) ? $this->results[$jobname] : null;
	}
	
	/**
	 * Checks if all jobs are ready
	 * @return void
	 */	
	protected function checkIfAllReady() {
		if ($this->resultsNum >= $this->jobsNum) {
			$this->jobs = [];
			$this->state = self::STATE_DONE;
			while ($cb = array_pop($this->listeners)) {
				call_user_func($cb, $this);
			}
		}
	}

	/**
	 * Adds job
	 * @param string Job name
	 * @param callable Callback
	 * @return boolean Success
	 */
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

	/**
	 * Clean up
	 * @return void
	 */	
	public function cleanup() {
		$this->listeners = [];
		$this->results = [];
		$this->jobs = [];
	}
	
	/**
	 * Adds listener
	 * @param callable Callback
	 * @return void
	 */
	public function addListener($cb) {
		if ($this->state === self::STATE_DONE) {
			call_user_func($cb, $this);
			return;
		}
		$this->listeners[] = CallbackWrapper::wrap($cb);
	}

	/**
	 * Runs the job
	 * @return void
	 */
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

	/**
	 * Adds new job or calls execute() method
	 * @return void
	 */	
	public function __invoke($name = null, $cb = null) {
		if (func_num_args() === 0) {
			$this->execute();
			return;
		}
		$this->addJob($name, $cb);
	}
}
