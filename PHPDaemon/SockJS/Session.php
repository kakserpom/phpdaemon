<?php
namespace PHPDaemon\SockJS;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\Structures\StackCallbacks;
use PHPDaemon\Utils\Crypt;
/**
 * @package    Libraries
 * @subpackage SockJS
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */

class Session {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/** @var \PHPDaemon\Request\Generic */
	public $route;

	/** @var \PHPDaemon\Structures\StackCallbacks */
	public $onWrite;

	public $id;

	public $appInstance;

	public $addr;


	/** @var array */
	public $buffer = [];

	public $framesBuffer = [];
	
	/** @var bool */
	public $finished = false;

	protected $onFinishedCalled = false;

	/** @var bool */
	public $flushing = false;
	
	/** @var int */
	public $timeout = 60;
	public $server;

	protected $pollMode;

	protected $running = false;

	protected $finishTimer;

	protected function toJson($m) {
		return json_encode($m, JSON_UNESCAPED_SLASHES);
	}

	/**
	 * @param $route
	 * @param $appInstance
	 * @param $authKey
	 */
	public function __construct($appInstance, $id, $server) {
		$this->onWrite   = new StackCallbacks;
		$this->id     = $id;
		$this->appInstance = $appInstance;
		$this->server = $server;
		$this->addr = $server['REMOTE_ADDR'];
		$this->finishTimer = setTimeout(function($timer) {
			$this->finish();
		}, $this->timeout * 1e6);
		
		$this->appInstance->subscribe('c2s:' . $this->id, [$this, 'c2s']);
		$this->appInstance->subscribe('poll:' . $this->id, [$this, 'poll'], function($redis) {
			$this->appInstance->publish('state:' . $this->id, 'started', function ($redis) {
				// @TODO: remove callback
			});
		});
	}

		/**
	 * Uncaught exception handler
	 * @param $e
	 * @return boolean Handled?
	 */
	public function handleException($e) {
		if (!isset($this->route)) {
			return false;
		}
		return $this->route->handleException($e);
	}


	/**
	 * Called when the request wakes up
	 * @return void
	 */
	public function onWakeup() {
		$this->running   = true;
		Daemon::$context = $this;
		$_SESSION = &$this->session;
		$_GET = &$this->get;
		$_POST = &$this->post; // supposed to be null
		$_COOKIE = &$this->cookie;
		Daemon::$process->setState(Daemon::WSTATE_BUSY);
	}


	/**
	 * Called when the request starts sleep
	 * @return void
	 */
	public function onSleep() {
		Daemon::$context = null;
		$this->running   = false;
		unset($_SESSION, $_GET, $_POST, $_COOKIE);
		Daemon::$process->setState(Daemon::WSTATE_IDLE);
	}

	public function onHandshake() {
		if (!isset($this->route)) {
			return;
		}
		$this->onWakeup();
		try {
			$this->route->onHandshake();
		} catch (\Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
		$this->onSleep();
	}

	public function c2s($redis) {
		if (!$redis) {
			return;
		}
		if ($this->finished) {
			return;
		}
		list (, $chan, $msg) = $redis->result;
		if ($msg === '') {
			return;
		}
		$this->onFrame($msg, \PHPDaemon\Servers\WebSocket\Pool::STRING);
	}

	public function onFrame($msg, $type) {
		$frames = json_decode($msg, true);
		if (!is_array($frames)) {
			return;
		}
		$this->onWakeup();
		foreach ($frames as $frame) {
			try {
				$this->route->onFrame($frame, \PHPDaemon\Servers\WebSocket\Pool::STRING);
			} catch (\Exception $e) {
				Daemon::uncaughtExceptionHandler($e);
			}
		}
		$this->onSleep();
	}

	public function poll($redis) {
		if (!$redis) {
			return;
		}
		list (, $chan, $msg) = $redis->result;
		$this->pollMode = json_decode($msg, true);

		Timer::setTimeout($this->finishTimer); 
		$this->flush();
	}

	/**
	 * @TODO DESCR
	 */
	public function onWrite() {
		$this->onWrite->executeAll($this->route);
		if (method_exists($this->route, 'onWrite')) {
			$this->route->onWrite();
		}
		if ($this->finished) {
			if (!sizeof($this->buffer) && !sizeof($this->framesBuffer)) {
				$this->onFinish();
			}
		}
		Timer::setTimeout($this->finishTimer); 
	}

	/**
	 * @TODO DESCR
	 */
	public function finish() {
		if ($this->finished) {
			return;
		}
		$this->finished = true;
		$this->sendPacket('c'.json_encode([3000,'Go away!']));
	}

	/*public function __destruct() {
		D('destructed session '.$this->id);
	}*/

	/**
	 * @TODO DESCR
	 */
	public function onFinish() {
		if ($this->onFinishedCalled) {
			return;
		}
		$this->onFinishedCalled = true;
		$this->appInstance->unsubscribe('c2s:' . $this->id, [$this, 'c2s']);
		$this->appInstance->unsubscribe('poll:' . $this->id, [$this, 'poll']);
		if (isset($this->route)) {
			$this->route->onFinish();
		}
		$this->onWrite->reset();
		$this->route = null;
		Timer::remove($this->finishTimer);
		$this->appInstance->endSession($this);
	}
	
	/**
	 * Flushes buffered packets
	 * @return void
	 */
	public function flush() {
		if ($this->pollMode === null) { // first polling request is not there yet
			return;
		}
		if ($this->flushing) {
			return;
		}
		$bsize = sizeof($this->buffer);
		$fbsize = sizeof($this->framesBuffer);
		if ($bsize === 0 && $fbsize === 0) {
			return;
		}
		$this->flushing = true;
		if (in_array('one-by-one', $this->pollMode)) {
			$b = array_slice($this->buffer, 0, 1);
			$bsize = sizeof($b);
		} else {
			$b = $this->buffer;
		}
		if ($fbsize > 0) {
			if (!in_array('one-by-one', $this->pollMode) || !sizeof($b)) {
				$b[] = 'a' . $this->toJson($this->framesBuffer);
			} else {
				$fbsize = 0;
			}
		}
		$this->appInstance->publish(
			's2c:' . $this->id,
			$this->toJson($b),
			function($redis) use ($bsize, $fbsize, $b) {
				$this->flushing = false;
				if (!$redis) {
					return;
				}
				//D(['b' => $b, $redis->result]);
				if ($redis->result === 0) {
					return;
				}
				$reflush = false;
				if (sizeof($this->buffer) > $bsize) {
					$this->buffer = array_slice($this->buffer, $bsize);
					$reflush = true;
				} else {
					$this->buffer = [];
				}

				if (sizeof($this->framesBuffer) > $fbsize) {
					$this->framesBuffer = array_slice($this->framesBuffer, $fbsize);
					$reflush = true;
				} else {
					$this->framesBuffer = [];
				}
				$this->onWrite();
				if ($reflush && in_array('stream', $this->pollMode)) {
					$this->flush();
				}
			}
		);
	}

	public function sendPacket($pct, $cb = null) {
		if (sizeof($this->framesBuffer)) {
			$this->buffer[] = 'a' . $this->toJson($this->framesBuffer);
			$this->framesBuffer = [];
		}
		$this->buffer[] = $pct;
		if ($cb !== null) {
			$this->onWrite->push($cb);
		}
		$this->flush();
	}

	/**
	 * Sends a frame.
	 * @param string   Frame's data.
	 * @param integer  Frame's type. See the constants.
	 * @param callback Optional. Callback called when the frame is received by client.
	 * @return boolean Success.
	 */
	public function sendFrame($data, $type = 0x00, $cb = null) {
		if ($this->finished) {
			return false;
		}
		$this->framesBuffer[] = $data;
		if ($cb !== null) {
			$this->onWrite->push($cb);
		}
		$this->flush();
		return true;
	}

}