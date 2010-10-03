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

	const INTERRUPT = 0;
	const DONE = 1;

	public $idAppQueue;
	public $appInstance;
	public $aborted = FALSE;
	public $state = 1;
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
	 * @method preint
	 * @description Preparing before init.
	 * @param object Source request.
	 * @return void
	 */
	public function preinit()
	{
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
		if ($this->state === 0) {
			return;
		}

		$this->sleepuntil = microtime(TRUE) + $time;

		if (!$set) {
			throw new RequestSleepException;
		}

		$this->state = 3;
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
		$this->state = 1;
	}

	/**
	 * @method call
	 * @description Called by queue dispatcher to touch the request.
	 * @return int Status.
	 */
	public function call() {
		if ($this->state === 0) {
			$this->state = 1;
			$this->finish();
			return 1;
		}

		if ($this->attrs->params_done) {
			if (isset($this->appInstance->passphrase)) {
				if (
					!isset($this->attrs->server['PASSPHRASE']) 
					|| ($this->appInstance->passphrase !== $this->attrs->server['PASSPHRASE'])
				) {
					$this->state = 1;
					return 1;
				}
			}
		}

		if (
			$this->attrs->params_done 
			&& $this->attrs->stdin_done
		) {
			$this->state = 2;
			$this->onWakeup();

			try {
				$ret = $this->run();

				if ($this->state === 0) {
					// Finished while running
					return 1;
				}

				$this->state = $ret;

				if ($this->state === NULL) {
					Daemon::log('Method ' . get_class($this) . '::run() returned null.');
				}
			} catch (RequestSleepException $e) {
				$this->state = 3;
			} catch (RequestTerminatedException $e) {
				$this->state = 1;
			}

			if ($this->state === 1) {
				$this->finish();
			}

			$this->onSleep();

			return $this->state;
		}

		return 0;
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
			&& ($this->state > 1) 
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
		if ($this->state === 0) {
			return;
		}

		if (!$zombie) {
			$this->state = 0;
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
			if (!$this->headers_sent) {
				$this->out('');
			}

			// $status: 0 - FCGI_REQUEST_COMPLETE, 1 - FCGI_CANT_MPX_CONN, 2 - FCGI_OVERLOADED, 3 - FCGI_UNKNOWN_ROLE
			$appStatus = 0;
			$this->upstream->endRequest($this, $appStatus, $status);

			if ($this->sendfp) {
				fclose($this->sendfp);
			}

			if (isset($this->attrs->files)) {
				foreach ($this->attrs->files as &$f) {
					if (
						($f['error'] === UPLOAD_ERR_OK) 
						&& file_exists($f['tmp_name'])
					) {
						unlink($f['tmp_name']);
					}
				}
			}

			if (isset($this->attrs->session)) {
				session_commit();
			}
		}
	}
}

class RequestSleepException extends Exception {}

class RequestTerminatedException extends Exception {}

class RequestHeadersAlreadySent extends Exception {}
