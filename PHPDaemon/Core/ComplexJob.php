<?php
namespace PHPDaemon\Core;

use PHPDaemon\Core\CallbackWrapper;

/**
 * ComplexJob class.
 */
class ComplexJob implements \ArrayAccess {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * State: waiting
	 * @var integer
	 */
	const STATE_WAITING = 1;
	
	/**
	 * State: running
	 * @var integer
	 */
	const STATE_RUNNING = 2;
	
	/**
	 * State: done
	 * @var integer
	 */
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
	 * Hash of jobs
	 * @var array [jobname -> callback, ...]
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

	protected $keep = false;
	

	protected $more = null;


	protected $maxConcurrency = -1;

	protected $backlog;

	/**
	 * Constructor
	 * @param callable $cb Listener
	 * @return \PHPDaemon\Core\ComplexJob
	 */
	public function __construct($cb = null) {
		$this->state = self::STATE_WAITING;
		if ($cb !== null) {
			$this->addListener($cb);
		}
	}
	public function offsetExists($j) {
		return isset($this->results[$j]);
	}
	public function offsetGet($j) {
		return isset($this->results[$j]) ? $this->results[$j] : null;
	}
	public function offsetSet($j, $v) {
		$this->setResult($j, $v);
	}
	public function offsetUnset($j) {
		unset($this->results[$j]);
	}
	public function getResults() {
		return $this->results;
	}
	/**
	 * Keep
	 * @param boolean Keep?
	 * @return void
	 */
	public function keep($keep = true) {
		$this->keep = (boolean) $keep;
	}

	/**
	 * Has completed?
	 * @return boolean
	 */
	public function hasCompleted() {
		return $this->state === self::STATE_DONE;
	}

	public function maxConcurrency($n = -1) {
		$this->maxConcurrency = $n;
		return $this;
	}

	/**
	 * Set result
	 * @param string Job name
	 * @param mixed  Result
	 * @return boolean
	 */
	public function setResult($jobname, $result = null) {
		if (isset($this->results[$jobname])) {
			return false;
		}
		$this->results[$jobname] = $result;
		++$this->resultsNum;
		$this->checkIfAllReady();
		return true;
	}

	/**
	 * Get result
	 * @param string Job name
	 * @param string $jobname
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
		$this->checkQueue();
		if ($this->resultsNum >= $this->jobsNum) {
			$this->jobs  = [];
			$this->state = self::STATE_DONE;
			foreach ($this->listeners as $cb) {
				call_user_func($cb, $this);
			}
			if (!$this->keep && $this->resultsNum >= $this->jobsNum) {
				$this->cleanup();
			}
		}
	}

	public function checkQueue() {
		if ($this->backlog !== null) {
			while (!$this->backlog->isEmpty()) {
				if ($this->maxConcurrency !== -1 && ($this->jobsNum - $this->resultsNum > $this->maxConcurrency)) {
					return;
				}
				list ($name, $cb) = $this->backlog->shift();
				$this->addJob($name, $cb);
			}
		}
		if ($this->more !== null) {
			$this->more();
		}
	}

	public function more($cb = null) {
		if ($cb !== null) {
			$this->more = $cb;
			return $this;
		}
		if ($this->more !== null) {
			if ($this->more instanceof \Iterator) {
				iterator:
				$it = $this->more;
				while (!$this->isQueueFull() && $it->valid()) {
					$this->addJob($it->key(), $it->current());
					$it->next();
				}
			} else {
				if (($r = call_user_func($this->more, $this)) instanceof \Iterator) {
					$this->more = $r;
					goto iterator;
				}
			}
			return $this;
		}
	}

	public function isQueueFull() {
		return $this->maxConcurrency !== -1 && ($this->jobsNum - $this->resultsNum >= $this->maxConcurrency);
	}

	/**
	 * Adds job
	 * @param string   Job name
	 * @param callable $cb Callback
	 * @return boolean Success
	 */
	public function addJob($name, $cb) {
		if (isset($this->jobs[$name])) {
			return false;
		}
		$cb = CallbackWrapper::wrap($cb);
		if ($this->maxConcurrency !== -1 && ($this->jobsNum - $this->resultsNum > $this->maxConcurrency)) {
			if ($this->backlog === null) {
				$this->backlog = new \SplStack;
			}
			$this->backlog->push([$name, $cb]);
			return true;
		}
		$this->jobs[$name] = $cb;
		++$this->jobsNum;
		if (($this->state === self::STATE_RUNNING) || ($this->state === self::STATE_DONE)) {
			$this->state = self::STATE_RUNNING;
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
		$this->results   = [];
		$this->jobs      = [];
		$this->more      = null;
	}

	/**
	 * Adds listener
	 * @param callable $cb Callback
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
	 * @param mixed $name
	 * @param callable $cb
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
