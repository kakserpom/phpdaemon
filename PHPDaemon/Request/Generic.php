<?php
namespace PHPDaemon\Request;

use PHPDaemon\Core\AppInstance;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

abstract class Generic {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;
	use \PHPDaemon\Traits\EventHandlers;

	/**
	 * State: finished.
	 * @var integer
	 */
	const STATE_FINISHED = 1;
	
	/**
	 * State: waiting.
	 * @var integer
	 */
	const STATE_WAITING  = 2;
	
	/**
	 * State: running.
	 * @var integer
	 */
	const STATE_RUNNING  = 3;

	/**
	 * Related Application instance
	 * @var \PHPDaemon\Core\AppInstance
	 */
	public $appInstance;

	/**
	 * Is this request aborted?
	 * @var boolean
	 */
	protected $aborted = false;

	/**
	 * State
	 * @var integer (self::STATE_*)
	 */
	protected $state = self::STATE_WAITING;

	/**
	 * Attributes
	 * @var \StdCLass
	 */
	public $attrs;

	/**
	 * Registered shutdown functions
	 * @var array
	 */
	protected $shutdownFuncs = [];

	/**
	 * Is this request running?
	 * @var boolean
	 */
	protected $running = false;

	/**
	 * Upstream
	 * @var object
	 */
	protected $upstream;

	/**
	 * Event
	 * @var object
	 */
	protected $ev;

	/**
	 * Current sleep() time
	 * @var float
	 */
	protected $sleepTime = 0;

	/**
	 * Priority
	 * @var integer
	 */
	protected $priority = null;

	/**
	 * Current code point
	 * @var mixed
	 */
	protected $codepoint;

	protected $autoinit = false; // @TODO: remove this option in future version

	/**
	 * Log
	 * @param string $msg Message
	 * @return void
	 */
	public function log($msg) {
		Daemon::log(get_class($this) . ': ' . $msg);
	}

	/**
	 * Constructor
	 * @param AppInstance $appInstance                        Parent AppInstance.
	 * @param IRequestUpstream $upstream                      Upstream.
	 * @param object $parent                                  Source request.
	 */
	public function __construct($appInstance, IRequestUpstream $upstream, $parent = null) {
		++Daemon::$process->reqCounter;
		$this->appInstance = $appInstance;
		$this->upstream    = $upstream;
		$this->ev          = \Event::timer(Daemon::$process->eventBase, [$this, 'eventCall']);
		if ($this->priority !== null) {
			$this->ev->priority = $this->priority;
		}
		$this->preinit($parent);
		if ($this->autoinit) {
			$this->callInit();
		}
	}

	public function callInit() {
		$this->onWakeup();
		try {
			$this->init();
		} catch (\Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
			$this->finish();
			return;
		}
		$this->onSleep();
	}

	/**
	 * Uncaught exception handler
	 * @param $e
	 * @return boolean|null Handled?
	 */
	public function handleException($e) {
	}

	/**
	 * Is this request aborted?
	 * @return boolean
	 */
	public function isAborted() {
		return $this->aborted;
	}

	/**
	 * Is this request finished?
	 * @return boolean
	 */
	public function isFinished() {
		return $this->state === static::STATE_FINISHED;
	}

	/**
	 * Is this request running?
	 * @return boolean
	 */
	public function isRunning() {
		return $this->running;
	}

	/**
	 * Output some data
	 * @param string $s String to out
	 * @param bool $flush
	 * @return boolean|null Success
	 */
	public function out($s, $flush = true) {
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	protected function run() {
	}

	/**
	 * Event handler of Request, called by Evtimer
	 * @param $arg
	 * @return void
	 */
	public function eventCall($arg) {
		try {
			if ($this->state === Generic::STATE_FINISHED) {
				$this->finish();
				$this->free();
				return;
			}
			$this->state = Generic::STATE_RUNNING;
			$this->onWakeup();
			$throw = false;
			try {
				$ret = $this->run();
				if (($ret === Generic::STATE_FINISHED) || ($ret === null)) {
					$this->finish();
				}
				elseif ($ret === Generic::STATE_WAITING) {
					$this->state = $ret;
				}
			} catch (RequestSleep $e) {
				$this->state = Generic::STATE_WAITING;
			} catch (RequestTerminated $e) {
				$this->state = Generic::STATE_FINISHED;
			} catch (\Exception $e) {
				if (!$this->handleException($e)) {
					$throw = true;
				}
			}
			if ($this->state === Generic::STATE_FINISHED) {
				$this->finish();
			}
			$this->onSleep();
			if ($throw) {
				throw $e;
			}

		} catch (\Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
			$this->finish();
			return;
		}
		if ($this->state === Generic::STATE_WAITING) {
			$this->ev->add($this->sleepTime);
		}
	}

	/**
	 * Frees the request
	 * @return void
	 */
	public function free() {
		if ($this->ev) {
			$this->ev->free();
			$this->ev = null;
		}
		if (isset($this->upstream)) {
			$this->upstream->freeRequest($this);
			$this->upstream = null;
		}
	}

	/**
	 * Sets the priority
	 * @param integer Priority
	 * @return void
	 */
	public function setPriority($p) {
		$this->priority = $p;
		if ($this->ev !== null) {
			$this->ev->priority = $p;
		}
	}

	/**
	 * Preparing before init
	 * @param object Source request
	 * @return void
	 */
	protected function preinit($req) {
		if ($req === NULL) {
			$req        = new \stdClass;
			$req->attrs = new \stdClass;
		}

		$this->attrs = $req->attrs;
	}

	/**
	 * This magic method called when the object casts to string
	 * @return string Description
	 */
	public function __toString() {
		return 'Request of type ' . get_class($this);
	}

	/**
	 * Called when request constructs
	 * @return void
	 */
	protected function init() {
	}

	/**
	 * Get string value from the given variable
	 * @param Reference of variable.
	 * @param array     Optional. Possible values.
	 * @return string Value.
	 */
	public static function getString(&$var, $values = null) {
		if (!is_string($var)) {
			$var = '';
		}
		if ($values !== null) {
			return in_array($var, $values, true) ? $var : $values[0];
		}

		return $var;
	}

	/**
	 * Get string value from the given variable
	 * @param Reference of variable.
	 * @return string Value.
	 */
	public static function getMixed(&$var) {
		return $var;
	}

	/**
	 * Get array value from the given variable
	 * @param Reference of variable.
	 * @param array     Optional. Filter callback.
	 * @return string Value.
	 */
	public static function getArray(&$var, $filter = null) {
		if (!is_array($var)) {
			return [];
		}
		if ($filter !== null) {
			return array_filter($var, $filter);
		}

		return $var;
	}

	/**
	 * Get integer value from the given variable
	 * @param Reference of variable.
	 * @param array     Optional. Possible values.
	 * @return string Value.
	 */
	public static function getInteger(&$var, $values = null) {
		if (is_string($var) && ctype_digit($var)) {
			$var = (int)$var;
		}
		if (!is_int($var)) {
			return 0;
		}
		if ($values !== null) {
			return in_array($var, $values, true) ? $var : $values[0];
		}

		return $var;
	}

	/**
	 * Called when the connection is ready to accept new data
	 * @return void
	 */
	public function onWrite() {
	}

	/**
	 * Adds new callback called before the request finished
	 * @param callable $callback
	 * @return void
	 */
	public function registerShutdownFunction($callback) {
		$this->shutdownFuncs[] = $callback;
	}

	/**
	 * Remove the given callback
	 * @param callable $callback
	 * @return void
	 */
	public function unregisterShutdownFunction($callback) {
		if (($k = array_search($callback, $this->shutdownFuncs)) !== FALSE) {
			unset($this->shutdownFuncs[$k]);
		}
	}

	/**
	 * Helper for easy switching between several interruptable stages of request's execution
	 * @param string Name
	 * @return boolean Execute
	 */
	public function codepoint($p) {
		if ($this->codepoint !== $p) {
			$this->codepoint = $p;
			return true;
		}

		return false;
	}

	/**
	 * Delays the request execution for the given number of seconds
	 *
	 * @param integer $time Time to sleep in seconds
	 * @param boolean $set    Set this parameter to true when use call it outside of Request->run() or if you don't want to interrupt execution now
	 * @throws RequestSleep
	 * @return void
	 */
	public function sleep($time = 0, $set = false) {
		if ($this->state === Generic::STATE_FINISHED) {
			return;
		}
		if ($this->state !== Generic::STATE_RUNNING) {
			$set = true;
		}

		$this->sleepTime = $time;

		if (!$set) {
			throw new RequestSleep;
		}
		else {
			$this->ev->del();
			$this->ev->add($this->sleepTime);
		}

		$this->state = Generic::STATE_WAITING;
	}

	/**
	 * Throws terminating exception
	 * @param $s
	 * @throws RequestTerminated
	 * @return void
	 */
	public function terminate($s = NULL) {
		if (is_string($s)) {
			$this->out($s);
		}

		throw new RequestTerminated;
	}

	/**
	 * Cancel current sleep
	 * @return void
	 */
	public function wakeup() {
		if ($this->state === Generic::STATE_WAITING) {
			$this->ev->del();
			$this->ev->add(0);
		}
	}

	/**
	 * Called to check if Request is ready
	 * @return boolean Ready?
	 */
	public function checkIfReady() {
		return true;
	}

	/**
	 * Called when the request aborted
	 * @return void
	 */
	public function onAbort() {
	}

	/**
	 * Called when the request finished
	 * @return void
	 */
	public function onFinish() {
	}

	/**
	 * Called when the request wakes up
	 * @return void
	 */
	public function onWakeup() {
		$this->running   = true;
		Daemon::$req     = $this;
		Daemon::$context = $this;
		Daemon::$process->setState(Daemon::WSTATE_BUSY);
	}

	/**
	 * Called when the request starts sleep
	 * @return void
	 */
	public function onSleep() {
		Daemon::$req     = null;
		Daemon::$context = null;
		$this->running   = false;
		Daemon::$process->setState(Daemon::WSTATE_IDLE);
	}

	/**
	 * Aborts the request
	 * @return void
	 */
	public function abort() {
		if ($this->aborted) {
			return;
		}

		$this->aborted = true;
		$this->onWakeup();
		$this->onAbort();

		if (
				(ignore_user_abort() === 1)
				&& (
						($this->state === Generic::STATE_RUNNING)
						|| ($this->state === Generic::STATE_WAITING)
				)
				&& !Daemon::$compatMode
		) {
				$this->upstream->endRequest($this);
		}
		else {
			$this->finish(-1);
		}

		$this->onSleep();
	}

	/**
	 * Finish the request
	 * @param integer Optional. Status. 0 - normal, -1 - abort, -2 - termination
	 * @param boolean Optional. Zombie. Default is false
	 * @return void
	 */
	public function finish($status = 0, $zombie = FALSE) {
		if ($this->state === Generic::STATE_FINISHED) {
			return;
		}

		if (!$zombie) {
			$this->state = Generic::STATE_FINISHED;
		}

		if (!($r = $this->running)) {
			$this->onWakeup();
		}

		while (($c = array_shift($this->shutdownFuncs)) !== NULL) {
			try {
				call_user_func($c, $this);
			} catch (\Exception $e) {
				Daemon::uncaughtExceptionHandler($e);
				// @TODO: break?
			}
		}

		if (!$r) {
			$this->onSleep();
		}

		$this->event('finish');
		$this->onFinish();
		$this->cleanupEventHandlers();

		if (Daemon::$compatMode) {
			return;
		}

		++Daemon::$process->counterGC;

		if (Daemon::$compatMode) {
			return;
		}

		if (!Daemon::$obInStack) { // preventing recursion
			ob_flush();
		}

		if ($status !== -1) {
			$appStatus = 0;
			$this->postFinishHandler(function () use ($appStatus, $status) {
				$this->upstream->endRequest($this, $appStatus, $status);
				$this->free();
			});
		} else {
			$this->free();
		}
	}

	/**
	 * Called after request finish
	 * @param callable $cb
	 * @return void
	 */
	protected function postFinishHandler($cb = null) {
		if ($cb) {
			call_user_func($cb);
		}
	}

}

