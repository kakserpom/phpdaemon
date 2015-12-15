<?php
namespace PHPDaemon\Core;

use PHPDaemon\Core\CallbackWrapper;

/**
 * ComplexJob class
 * @package PHPDaemon\Core
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class ComplexJob implements \ArrayAccess {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * State: waiting
	 */
	const STATE_WAITING = 1;
	
	/**
	 * State: running
	 */
	const STATE_RUNNING = 2;
	
	/**
	 * State: done
	 */
	const STATE_DONE = 3;

	/**
	 * @var array Listeners [callable, ...]
	 */
	public $listeners = [];

	/**
	 * @var array Hash of results [jobname -> result, ...]
	 */
	public $results = [];

	/**
	 * @var integer Current state
	 */
	public $state;

	/**
	 * @var array Hash of jobs [jobname -> callback, ...]
	 */
	public $jobs = [];

	/**
	 * @var integer Number of results
	 */
	public $resultsNum = 0;

	/**
	 * @var integer Number of jobs
	 */
	public $jobsNum = 0;

	protected $keep = false;

	protected $more = null;

	protected $maxConcurrency = -1;

	protected $backlog;

	/**
	 * Constructor
	 * @param callable $cb Listener
	 */
	public function __construct($cb = null) {
		$this->state = self::STATE_WAITING;
		if ($cb !== null) {
			$this->addListener($cb);
		}
	}

	/**
	 * Handler of isset($job[$name])
	 * @param  string $j Job name
	 * @return boolean
	 */
	public function offsetExists($j) {
		return isset($this->results[$j]);
	}

	/**
	 * Handler of $job[$name]
	 * @param  string $j Job name
	 * @return mixed
	 */
	public function offsetGet($j) {
		return isset($this->results[$j]) ? $this->results[$j] : null;
	}

	/**
	 * Handler of $job[$name] = $value
	 * @param  string $j Job name
	 * @param  mixed  $v Job result
	 * @return void
	 */
	public function offsetSet($j, $v) {
		$this->setResult($j, $v);
	}

	/**
	 * Handler of unset($job[$name])
	 * @param  string $j Job name
	 * @return void
	 */
	public function offsetUnset($j) {
		unset($this->results[$j]);
	}

	/**
	 * Returns associative array of results
	 * @return array
	 */
	public function getResults() {
		return $this->results;
	}
	/**
	 * Keep
	 * @param  boolean $keep Keep?
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

	/**
	 * Sets a limit of simultaneously executing tasks
	 * @param  integer $n Natural number or -1 (no limit)
	 * @return this
	 */
	public function maxConcurrency($n = -1) {
		$this->maxConcurrency = $n;
		return $this;
	}

	/**
	 * Set result
	 * @param  string $jobname Job name
	 * @param  mixed  $result  Result
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
	 * @param  string $jobname Job name
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

	/**
	 * Called automatically. Checks whether if the queue is full. If not, tries to pull more jobs from backlog and 'more'
	 * @return void
	 */
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

	/**
	 * Sets a callback which is going to be fired always when we have a room for more jobs
	 * @param  callable $cb Callback
	 * @return this
	 */
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

	/**
	 * Returns whether or not the queue is full (maxConcurrency option exceed)
	 * @return boolean
	 */
	public function isQueueFull() {
		return $this->maxConcurrency !== -1 && ($this->jobsNum - $this->resultsNum >= $this->maxConcurrency);
	}

	/**
	 * Adds job
	 * @param  string   $name Job name
	 * @param  callable $cb   Callback
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
	 * @param  callable $cb Callback
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
	 * @param  mixed    $name
	 * @param  callable $cb
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
