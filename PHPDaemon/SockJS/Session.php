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

	/** @var bool */
	public $flushing = false;
	
	/** @var int */
	public $timeout = 60;
	public $server;


	protected $finishTimer;

	protected function toJson($m) {
		return json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
		$this->appInstance->subscribe('poll:' . $this->id, [$this, 'poll']);
	}

	public function c2s($redis) {
		if (!$redis) {
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
		foreach ($frames as $frame) {
			$this->route->onFrame($frame, \PHPDaemon\Servers\WebSocket\Pool::STRING);
		}
	}

	public function poll($redis) {
		Timer::setTimeout($this->finishTimer); 
		$this->flush();
	}
	
	/**
	 * @TODO DESCR
	 */
	public function onHandshake() {
		$this->sendPacket('o');
		$this->route->onHandshake();
	}

	/**
	 * @TODO DESCR
	 */
	public function onWrite() {
		if ($this->finished) {
			return;
		}
		Timer::setTimeout($this->finishTimer); 
		$this->onWrite->executeAll($this->route);
		if (method_exists($this->route, 'onWrite')) {
			$this->route->onWrite();
		}
	}

	/**
	 * @TODO DESCR
	 */
	public function finish() {
		if ($this->finished) {
			return;
		}
		$this->sendPacket('c["Go away!"]');
		$this->finished = true;
		$this->onFinish();
	}

	/*public function __destruct() {
		D('destructed session '.$this->id);
	}*/

	/**
	 * @TODO DESCR
	 */
	public function onFinish() {
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
		if ($this->flushing) {
			return;
		}
		$bsize = sizeof($this->buffer);
		$fbsize = sizeof($this->framesBuffer);
		if ($bsize === 0 && $fbsize === 0) {
			return;
		}
		$this->flushing = true;
		$b = $this->buffer;
		if ($fbsize > 0) {
			$b[] = 'a' . $this->toJson($this->framesBuffer);
		}
		$this->appInstance->publish(
			's2c:' . $this->id,
			$this->toJson($b),
			function($redis) use ($bsize, $fbsize, $b) {
				$this->flushing = false;
				if (!$redis) {
					return;
				}
				if ($redis->result === 0) {
					//D(['b' => $b, $redis->result]);
					return;
				}
				if (sizeof($this->buffer) > $bsize) {
					$this->buffer = array_slice($this->buffer, $bsize);
					$this->flush();
				} else {
					$this->buffer = [];
				}

				if (sizeof($this->framesBuffer) > $fbsize) {
					$this->framesBuffer = array_slice($this->framesBuffer, $fbsize);
					$this->flush();
				} else {
					$this->framesBuffer = [];
				}
				$this->onWrite();
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
		$this->framesBuffer[] = $data;
		if ($cb !== null) {
			$this->onWrite->push($cb);
		}
		$this->flush();
		return true;
	}

}