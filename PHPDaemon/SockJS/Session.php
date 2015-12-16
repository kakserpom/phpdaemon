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
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Session {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var \PHPDaemon\Request\Generic
	 */
	public $route;

	/**
	 * @var \PHPDaemon\Structures\StackCallbacks
	 */
	public $onWrite;

	public $id;

	public $appInstance;

	public $addr;


	/**
	 * @var array
	 */
	public $buffer = [];

	public $framesBuffer = [];
	
	/**
	 * @var boolean
	 */
	public $finished = false;

	protected $onFinishedCalled = false;

	/**
	 * @var boolean
	 */
	public $flushing = false;
	
	/**
	 * @var integer
	 */
	public $timeout = 60;
	public $server;
	public $get;
	public $cookie;
	public $post;

	protected $pollMode;

	protected $running = false;

	protected $finishTimer;

	/**
	 * toJson
	 * @param  string $m
	 * @return string
	 */
	protected function toJson($m) {
		return json_encode($m, JSON_UNESCAPED_SLASHES);
	}

	/**
	 * __construct
	 * @param Application $appInstance [@todo description]
	 * @param string      $id          [@todo description]
	 * @param array       $server      [@todo description]
	 * @return void
	 */
	public function __construct($appInstance, $id, $server) {
		$this->onWrite   = new StackCallbacks;
		$this->id     = $id;
		$this->appInstance = $appInstance;
		$this->server = $server;
		
		if (isset($this->server['HTTP_COOKIE'])) {
			Generic::parse_str(strtr($this->server['HTTP_COOKIE'], Generic::$hvaltr), $this->cookie);
		}
		if (isset($this->server['QUERY_STRING'])) {
			Generic::parse_str($this->server['QUERY_STRING'], $this->get);
		}
		
		$this->addr = $server['REMOTE_ADDR'];
		$this->finishTimer = setTimeout(function($timer) {
			$this->finish();
		}, $this->timeout * 1e6);
		
		$this->appInstance->subscribe('c2s:' . $this->id, [$this, 'c2s']);
		$this->appInstance->subscribe('poll:' . $this->id, [$this, 'poll'], function($redis) {
			$this->appInstance->publish('state:' . $this->id, 'started', function ($redis) {
				// @TODO: remove this callback
			});
		});
	}

	/**
	 * Uncaught exception handler
	 * @param  object $e
	 * @return boolean|null Handled?
	 */
	public function handleException($e) {
		if (!isset($this->route)) {
			return false;
		}
		return $this->route->handleException($e);
	}

	/**
	 * onHandshake
	 * @return void
	 */
	public function onHandshake() {
		if (!isset($this->route)) {
			return;
		}
		$this->route->onWakeup();
		try {
			$this->route->onHandshake();
		} catch (\Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
		$this->route->onSleep();
	}

	/**
	 * c2s
	 * @param  object $redis
	 * @return void
	 */
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

	/**
	 * onFrame
	 * @param  string  $msg  [@todo description]
	 * @param  integer $type [@todo description]
	 * @return void
	 */
	public function onFrame($msg, $type) {
		$frames = json_decode($msg, true);
		if (!is_array($frames)) {
			return;
		}
		$this->route->onWakeup();
		foreach ($frames as $frame) {
			try {
				$this->route->onFrame($frame, \PHPDaemon\Servers\WebSocket\Pool::STRING);
			} catch (\Exception $e) {
				Daemon::uncaughtExceptionHandler($e);
			}
		}
		$this->route->onSleep();
	}

	/**
	 * poll
	 * @param  object $redis
	 * @return void
	 */
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
	 * @return void
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
	 * @return void
	 */
	public function finish() {
		if ($this->finished) {
			return;
		}
		$this->finished = true;
		$this->onFinish();
		$this->sendPacket('c'.json_encode([3000,'Go away!']));
	}

	/*public function __destruct() {
		D('destructed session '.$this->id);
	}*/

	/**
	 * @TODO DESCR
	 * @return void
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

	/**
	 * sendPacket
	 * @param  object   $pct [@todo description]
	 * @param  callable $cb  [@todo description]
	 * @callback $cb ( )
	 * @return void
	 */
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
	 * @param  string   $data Frame's data.
	 * @param  integer  $type Frame's type. See the constants.
	 * @param  callback $cb   Optional. Callback called when the frame is received by client.
	 * @callback $cb ( )
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
