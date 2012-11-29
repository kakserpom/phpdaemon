<?php
 
/**
 * Request
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Request {
 
	const INTERRUPT = 3; // alias of STATE_SLEEPING
	const DONE      = 0; // alias of STATE_FINISHED
 
	const STATE_FINISHED = 0;
	const STATE_ALIVE    = 1;
	const STATE_RUNNING  = 2;
	const STATE_SLEEPING = 3;
	public $conn;
	public $appInstance;
	public $aborted = FALSE;
	public $state = self::STATE_ALIVE;
	public $codepoint;
	public $sendfp;
	public $attrs;
	public $shutdownFuncs = array();
	public $running = FALSE;
	public $upstream;
	public $ev;
	public $sleepTime = 1000;
	public $priority = null;
 
	/**
	 * Constructor
	 * @param object Parent AppInstance.
	 * @param object Upstream.
	 * @param object Source request.
	 * @return void
	 */
	public function __construct($appInstance, $upstream, $parent = NULL) {
		$this->appInstance = $appInstance;
		$this->upstream = $upstream;
		$this->ev = event_timer_new();
		event_timer_set($this->ev, array($this, 'eventCall'));
		event_base_set($this->ev, Daemon::$process->eventBase);
		if ($this->priority !== null) {
			event_priority_set($this->ev, $this->priority);
		}
		event_timer_add($this->ev, 1);
				
		$this->preinit($parent);
		$this->onWakeup();
		$this->init();
		$this->onSleep();
	}
 	
 	/**
	 * Output some data
	 * @param string String to out
	 * @return boolean Success
	 */
	public function out($s, $flush = true) {
	}
	
	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {}
	
	/**
	 * @todo description is missing
	 */
	public function eventCall($fd, $flags, $arg) {		
		if ($this->state === Request::STATE_SLEEPING) {
			$this->state = Request::STATE_ALIVE;
		}
		try {
			$ret = $this->call();
		} catch (Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
			$this->finish();
			return;
		}
		if ($ret === Request::STATE_FINISHED) {		
			$this->free();

		}
		elseif ($ret === REQUEST::STATE_SLEEPING) {
			event_add($this->ev, $this->sleepTime);
		}
	}
	public function free() {
		if (is_resource($this->ev)) {
			event_timer_del($this->ev);
			event_free($this->ev);
		}
		if (isset($this->conn)) {
			$this->conn->freeRequest($this);
		}
	}
	public function setPriority($p) {
		$this->priority = $p;
		if ($this->ev !== null) {
			event_priority_set($this->ev, $p);
		}
		
	}
	
	/**
	 * Called by queue dispatcher to touch the request
	 * @return int Status
	 */
	public function call() {
		if ($this->state === Request::STATE_FINISHED) {
			$this->state = Request::STATE_ALIVE;
			$this->finish();
			return Request::STATE_FINISHED;
		}
 
		$this->state = Request::STATE_ALIVE;
		
		$this->preCall();
 
		if ($this->state !== Request::STATE_ALIVE) {
			return $this->state;
		}
		
		$this->state = Request::STATE_RUNNING;
		
		$this->onWakeup();
 
		try {
			$ret = $this->run();
 
			if ($this->state === Request::STATE_FINISHED) {
				// Finished while running
				return Request::STATE_FINISHED;
			}
 
			if ($ret === NULL) {
				$ret = Request::STATE_FINISHED;
			}
			if ($ret === Request::STATE_FINISHED) {
				$this->finish();
			}
			elseif ($ret === Request::STATE_SLEEPING) {
				$this->state = $ret;
			}
		} catch (RequestSleepException $e) {
			$this->state = Request::STATE_SLEEPING;
		} catch (RequestTerminatedException $e) {
			$this->state = Request::STATE_FINISHED;
		}
 
		if ($this->state === Request::STATE_FINISHED) {
			$this->finish();
		}
 
		$this->onSleep();
 
		return $this->state;
	}
	
	/**
	 * Preparing before init
	 * @param object Source request
	 * @return void
	 */
	public function preinit($req)
	{
		if ($req === NULL) {
			$req = new stdClass;
			$req->attrs = new stdClass;
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
	protected function init() { }
 
	/**
	 * Get string value from the given variable
	 * @param Reference of variable.
	 * @param array Optional. Possible values.
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
	 * Get array value from the given variable
	 * @param Reference of variable.
	 * @param array Optional. Filter callback.
	 * @return string Value.
	 */
	public static function getArray(&$var, $filter = null) {
		if (!is_array($var)) {
			 return array();
		}
		if ($filter !== null) {
			return array_filter($var, $filter);
		}
 
		return $var;
	}
	
		/**
	 * Get integer value from the given variable
	 * @param Reference of variable.
	 * @param array Optional. Possible values.
	 * @return string Value.
	 */
	public static function getInteger(&$var, $values = null) {
		if (is_string($var) && ctype_digit($var)) {
			$var = (int) $var;
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
	public function onWrite() { }
 
	/**
	 * Adds new callback called before the request finished
	 * @return void
	 */
	public function registerShutdownFunction($callback) {
		$this->shutdownFuncs[] = $callback;
	}
 
	/**
	 * Remove the given callback
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
			return TRUE;
		}
 
		return FALSE;
	}
 
	/**
	 * Delays the request execution for the given number of seconds
	 * @throws RequestSleepException
	 * @param float Time to sleep in seconds
	 * @param boolean Set this parameter to true when use call it outside of Request->run() or if you don't want to interrupt execution now
	 * @return void
	 */
	public function sleep($time = 0, $set = false) {
		if ($this->state === Request::STATE_FINISHED) {
			return;
		}
		if ($this->state !== Request::STATE_RUNNING) {
			$set = true;
		}
 
		$this->sleepTime = $time*1000000;
 
		if (!$set) {
			throw new RequestSleepException;
		}
		else {
			event_timer_del($this->ev);
			event_timer_add($this->ev, $this->sleepTime);
		}
 
		$this->state = Request::STATE_SLEEPING;
	}
 
	/**
	 * Throws terminating exception
	 * @return void
	 */
	public function terminate($s = NULL) {
		if (is_string($s)) {
			$this->out($s);
		}
 
		throw new RequestTerminatedException;
	}
 
	/**
	 * Cancel current sleep
	 * @return void
	 */
	public function wakeup() {
		if (is_resource($this->ev)) {
			$this->state = Request::STATE_ALIVE;
			event_timer_del($this->ev);
			event_timer_add($this->ev, 1);
		}
	}
	
	/**
	 * Called by call() to check if ready
	 * @todo -> protected?
	 * @return void
	 */
	public function preCall() {
		return TRUE;
	}
 
	/**
	 * Called when the request aborted
	 * @todo protected?
	 * @return void
	 */
	public function onAbort() { }
 
	/**
	 * Called when the request finished
	 * @todo protected?
	 * @return void
	 */
	public function onFinish() { }
 
	/**
	 * Called when the request wakes up
	 * @return void
	 */
	public function onWakeup() {
		if (!Daemon::$compatMode) {
			Daemon::$process->setStatus(2);
		}
 
		$this->running = true;
 
		Daemon::$req = $this;
		Daemon::$context = $this;
	}
 
	/**
	 * Called when the request starts sleep
	 * @return void
	 */
	public function onSleep() { 
		if (!Daemon::$compatMode) {
			Daemon::$process->setStatus(1);
		}
 
		Daemon::$req = NULL;
		Daemon::$context = NULL;
		$this->running = FALSE;
	}	
 
	/**
	 * Aborts the request
	 * @return void
	 */
	public function abort() {
		if ($this->aborted) {
			return;
		}
 
		$this->aborted = TRUE;
		$this->onWakeup();
		$this->onAbort();
 
		if (
			(ignore_user_abort() === 1) 
			&& (
				($this->state === Request::STATE_RUNNING) 
				|| ($this->state === Request::STATE_SLEEPING)
			)
			&& !Daemon::$compatMode
		) {
			if (
				!isset($this->upstream->keepalive->value) 
				|| !$this->upstream->keepalive->value
			) {
				$this->conn->endRequest($this);
			}
		} else {
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
		if ($this->state === Request::STATE_FINISHED) {
			return;
		}
 
		if (!$zombie) {
			$this->state = Request::STATE_FINISHED;
		}
 
		if (!($r = $this->running)) {
			$this->onWakeup();
		}
 
		while (($c = array_shift($this->shutdownFuncs)) !== NULL) {
			call_user_func($c, $this);
		}
 
		if (!$r) {
			$this->onSleep();
		}
 
		$this->onFinish();
 
		if (Daemon::$compatMode) {
			return;
		}
 
		Daemon::callAutoGC();
 
		if (Daemon::$compatMode) {
			return;
		}
 
		if (!Daemon::$obInStack) { // preventing recursion
				ob_flush();
			}
 
		if ($status !== -1) {
			$this->postFinishHandler();
			// $status: 0 - FCGI_REQUEST_COMPLETE, 1 - FCGI_CANT_MPX_CONN, 2 - FCGI_OVERLOADED, 3 - FCGI_UNKNOWN_ROLE  @todo what is -1 ? where is the constant for it?
			$appStatus = 0;
			if (isset($this->conn)) {
				$this->conn->endRequest($this, $appStatus, $status);
			}
 
		}
	}
 
	public function postFinishHandler() { }
	
	public function onDestruct() {}
	
	public function __destruct() {
		$this->onDestruct();
	}
}
 
class RequestSleepException extends Exception {}
class RequestTerminatedException extends Exception {}
class RequestHeadersAlreadySent extends Exception {}