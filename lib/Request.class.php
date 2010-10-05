<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Request
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description Request class.
/**************************************************************************/

class Request {

	const INTERRUPT = 3; // alias of STATE_SLEEPING
	const DONE = 0; // alias of STATE_FINISHED

	const STATE_FINISHED = 0;
	const STATE_ALIVE = 1;
	const STATE_RUNNING = 2;
	const STATE_SLEEPING = 3;
	public $idAppQueue;
	public $appInstance;
	public $aborted = FALSE;
	public $state = 1; // 0 - finished, 1 - alive, 2 - running, 3 - sleeping
	public $codepoint;
	public $sendfp;
	public $attrs;
	public $shutdownFuncs = array();
	public $sleepuntil;
	public $running = FALSE;
	public $upstream;

	/**
	 * @method __construct
	 * @description 
	 * @param object Parent AppInstance.
	 * @param object Upstream.
	 * @param object Source request.
	 * @return void
	 */
	public function __construct($appInstance, $upstream, $req = NULL) {

		$this->appInstance = $appInstance;
		$this->upstream = $upstream;

		$this->preinit($req);
		$this->onWakeup();
		$this->init();
		$this->onSleep();
	}
	/**
	 * @method preinit
	 * @description Preparing before init.
	 * @param object Source request.
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
	 * @method __toString()
	 * @description This magic method called when the object casts to string.
	 * @return string Description.
	 */
	public function __toString() {
		return 'Request of type ' . get_class($this);
	}


	/**
	 * @method init
	 * @description Called when request constructs.
	 * @return void
	 */
	protected function init() {}

	/**
	 * @method getString
	 * @param Reference of variable.
	 * @description Gets string value from the given variable.
	 * @return string Value.
	 */
	public function getString(&$var) {
		if (!is_string($var)) {
			return '';
		}

		return $var;
	}

	/**
	 * @method onWrite
	 * @description Called when the connection is ready to accept new data.
	 * @return void
	 */
	public function onWrite() {}

	/**
	 * @method registerShutdownFunction
	 * @description Adds new callback called before the request finished.
	 * @return void
	 */
	public function registerShutdownFunction($callback) {
		$this->shutdownFuncs[] = $callback;
	}

	/**
	 * @method unregisterShutdownFunction
	 * @description Remove the given callback.
	 * @return void
	 */
	public function unregisterShutdownFunction($callback) {
		if (($k = array_search($callback, $this->shutdownFuncs)) !== FALSE) {
			$this->shutdownFuncs[] = $callback;
		}
	}

	/**
	 * @method codepoint
	 * @param string Name.
	 * @description Helper for easy switching between several interruptable stages of request's execution.
	 * @return boolean Execute.
	 */
	public function codepoint($p) {
		if ($this->codepoint !== $p) {
			$this->codepoint = $p;

			return TRUE;
		}

		return FALSE;
	}

	/**
	 * @method sleep
	 * @throws RequestSleepException
	 * @param float Time to sleep in seconds.
	 * @param boolean Set this parameter to true when use call it outside of Request->run() or if you don't want to interrupt execution now.
	 * @description Delays the request execution for the given number of seconds.
	 * @return void
	 */
	public function sleep($time = 0, $set = FALSE) {
		if ($this->state === Request::STATE_FINISHED) {
			return;
		}

		$this->sleepuntil = microtime(TRUE) + $time;

		if (!$set) {
			throw new RequestSleepException;
		}

		$this->state = Request::STATE_SLEEPING;
	}

	/**
	 * @method terminate
	 * @description Throws terminating exception.
	 * @return void
	 */
	public function terminate($s = NULL) {
		if (is_string($s)) {
			$this->out($s);
		}

		throw new RequestTerminatedException;
	}

	/**
	 * @method wakeup
	 * @description Cancel current sleep.
	 * @return void
	 */
	public function wakeup() {
		$this->state = Request::STATE_ALIVE;
	}
	/**
	 * @method precall
	 * @description Called by call() to check if ready.
	 * @return void
	 */
	public function preCall()
	{
		return TRUE;
	}
	/**
	 * @method call
	 * @description Called by queue dispatcher to touch the request.
	 * @return int Status.
	 */
	public function call() {
		if ($this->state === Request::STATE_FINISHED) {
			$this->state = Request::STATE_ALIVE;
			$this->finish();
			return Request::STATE_FINISHED;
		}

		$this->preCall();
		if ($this->state !== Request::STATE_ALIVE)
		{
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
	 * @method onAbort
	 * @description Called when the request aborted.
	 * @return void
	 */
	public function onAbort() {}

	/**
	 * @method onFinish
	 * @description Called when the request finished.
	 * @return void
	 */
	public function onFinish() {}

	/**
	 * @method onWakeUp
	 * @description Called when the request wakes up.
	 * @return void
	 */
	protected function onWakeup() {
		if (!Daemon::$compatMode) {
			Daemon::$worker->setStatus(2);
		}

		ob_flush();

		$this->running = TRUE;

		Daemon::$req = $this;
	}

	/**
	 * @method onSleep
	 * @description Called when the request starts sleep.
	 * @return void
	 */
	public function onSleep() {
		ob_flush();

		if (!Daemon::$compatMode) {
			Daemon::$worker->setStatus(1);
		}

		Daemon::$req = NULL;
		$this->running = FALSE;
	}	

	/**
	 * @method abort
	 * @description Aborts the request.
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
			&& (($this->state === Request::STATE_RUNNING) || ($this->state === Request::STATE_SLEEPING))
			&& !Daemon::$compatMode
		) {
			if (!isset($this->upstream->keepalive->value) || !$this->upstream->keepalive->value) {
				$this->upstream->closeConnection($this->attrs->connId);
			}
		} else {
			$this->finish(-1);
		}

		$this->onSleep();
	}

	/**
	 * @method finish
	 * @param integer Optional. Status. 0 - normal, -1 - abort, -2 - termination
	 * @param boolean Optional. Zombie. Default is false.
	 * @description Finishes the request.
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

		if (
			(Daemon::$config->autogc->value > 0) 
			&& (Daemon::$worker->queryCounter > 0) 
			&& (Daemon::$worker->queryCounter % Daemon::$config->autogc->value === 0)
		) {
			gc_collect_cycles();
		}

		if (Daemon::$compatMode) {
			return;
		}

		ob_flush();

		if ($status !== -1) {
			$this->postFinishHandler();
			// $status: 0 - FCGI_REQUEST_COMPLETE, 1 - FCGI_CANT_MPX_CONN, 2 - FCGI_OVERLOADED, 3 - FCGI_UNKNOWN_ROLE
			$appStatus = 0;
			$this->upstream->endRequest($this, $appStatus, $status);

		}
	}
	public function postFinishHandler()
	{
	}
}

class RequestSleepException extends Exception {}

class RequestTerminatedException extends Exception {}

class RequestHeadersAlreadySent extends Exception {}
