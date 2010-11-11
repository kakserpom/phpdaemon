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

	public $idAppQueue;
	public $queueId;
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

		$this->idAppQueue = ++$this->appInstance->idAppQueue;
		$this->appInstance->queue[$this->idAppQueue] = $this;
		
		$this->queueId = isset($parent->queueId)?$parent->queueId:(++Daemon::$process->reqCounter);
		Daemon::$process->queue[$this->queueId] = $this;
		
		$this->preinit($parent);
		$this->onWakeup();
		$this->init();
		$this->onSleep();
		
		$this->ev = event_new();

		event_set(
			$this->ev, STDIN, EV_TIMEOUT, 
			array('Request', 'eventCall'), 
			array($this->queueId)
		);
		event_base_set($this->ev, Daemon::$process->eventBase);
		event_add($this->ev, 100);
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {}
	
	/**
	 * @todo description is missing
	 */
	public static function eventCall($fd, $flags, $arg) {
		$k = $arg[0];

		if (!isset(Daemon::$process->queue[$k])) {
			Daemon::log('Bad event call.');
			return;
		}

		$r = Daemon::$process->queue[$k];
		
		if ($r->state === Request::STATE_SLEEPING) {
			$r->state = Request::STATE_ALIVE;
		}
		
		if (Daemon::$config->logqueue->value) {
			Daemon::$process->log('event ' . get_class($r) . '::call() invoked.');
		}

		$ret = $r->call();
	
		if (Daemon::$config->logqueue->value) {
			Daemon::$process->log('event runQueue(): (' . $k . ') -> ' . get_class($r) . '::call() returned ' . $ret . '.');
		}

		if ($ret === Request::STATE_FINISHED) {		
			if (is_resource($r->ev)) {
				event_del($r->ev);
				event_free($r->ev);
			}
			
			unset(Daemon::$process->queue[$k]);

			if (isset($r->idAppQueue)) {
				if (Daemon::$config->logqueue->value) {
					Daemon::$process->log('request removed from ' . get_class($r->appInstance) . '->queue.');
				}

				unset($r->appInstance->queue[$r->idAppQueue]);
			} else {
				if (Daemon::$config->logqueue->value) {
					Daemon::$process->log('request can\'t be removed from AppInstance->queue.');
				}
			}
		}
		elseif ($ret === REQUEST::STATE_SLEEPING) {
			event_add($r->ev, $r->sleepTime);
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
	 * @return string Value.
	 */
	public function getString(&$var) {
		if (!is_string($var)) {
			return '';
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
			$this->shutdownFuncs[] = $callback;
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
	public function sleep($time = 0, $set = FALSE) {
		if ($this->state === Request::STATE_FINISHED) {
			return;
		}

		$this->sleepTime = $time*1000000;

		if (!$set) {
			throw new RequestSleepException;
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
			event_del($this->ev);
			event_add($this->ev, 1);
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
	 * @todo protected?
	 * @return void
	 */
	protected function onWakeup() {
		if (!Daemon::$compatMode) {
			Daemon::$process->setStatus(2);
		}

		ob_flush();

		$this->running = TRUE;

		Daemon::$req = $this;
	}

	/**
	 * Called when the request starts sleep
	 * @todo protected?
	 * @return void
	 */
	public function onSleep() {
		ob_flush();

		if (!Daemon::$compatMode) {
			Daemon::$process->setStatus(1);
		}

		Daemon::$req = NULL;
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
				$this->upstream->closeConnection($this->attrs->connId);
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

		if (
			(Daemon::$config->autogc->value > 0) 
			&& (Daemon::$process->reqCounter > 0) 
			&& (Daemon::$process->reqCounter % Daemon::$config->autogc->value === 0)
		) {
			gc_collect_cycles();
		}

		if (Daemon::$compatMode) {
			return;
		}

		ob_flush();

		if ($status !== -1) {
			$this->postFinishHandler();
			// $status: 0 - FCGI_REQUEST_COMPLETE, 1 - FCGI_CANT_MPX_CONN, 2 - FCGI_OVERLOADED, 3 - FCGI_UNKNOWN_ROLE  @todo what is -1 ? where is the constant for it?
			$appStatus = 0;
			$this->upstream->endRequest($this, $appStatus, $status);

		}
	}

	public function postFinishHandler() { }
	
	public function onDestruct() {}
	
	public function __destruct() {
		if (is_resource($this->ev)) {
			event_del($this->ev);
			event_free($this->ev);
		}
		$this->onDestruct();
	}
}

class RequestSleepException extends Exception {}
class RequestTerminatedException extends Exception {}
class RequestHeadersAlreadySent extends Exception {}
