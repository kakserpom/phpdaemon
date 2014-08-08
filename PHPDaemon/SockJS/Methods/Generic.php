<?php
namespace PHPDaemon\SockJS\Methods;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\Exceptions\UndefinedMethodCalled;

/**
 * Contains some base methods
 *
 * @package Libraries
 * @subpackage SockJS
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */

abstract class Generic extends \PHPDaemon\HTTPRequest\Generic {
	protected $stage = 0;
	protected $sessId;
	protected $serverId;
	protected $path;
	protected $timer;
	
	protected $delayedStopEnabled = false;
	protected $callbackParamEnabled = false;
	protected $frames = [];

	protected $errors = [
		2010 => 'Another connection still open',
	];

	protected $fillerSent = false;
	protected $fillerEnabled = false;

	protected $cacheable = false;
	protected $poll = false;

	public function init() {
		$this->CORS();
		$this->contentType($this->contentType);
		if (!$this->cacheable) {
			$this->noncache();
		}
		if ($this->callbackParamEnabled) {
			if (!isset($_GET['c']) || !is_string($_GET['c']) || preg_match('~[^_\.a-zA-Z0-9]~', $_GET['c'])) {
				$this->header('400 Bad Request');
				$this->finish();
				return;
			}
		}
		if ($this->poll) {
			$this->acquire(function() {
				$this->poll();
			});
		}
	}


	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		$this->sleep(30);
	}


	public function w8in($redis) {}
	
	public function s2c($redis) {
		list (, $chan, $msg) = $redis->result;
		$frames = json_decode($msg, true);
		if (!is_array($frames) || !sizeof($frames)) {
			return;
		}
		if ($this->fillerEnabled && !$this->fillerSent) {
			$this->sendFrame(str_repeat('h', 2048) . "\n");
			$this->fillerSent = true;
		}
		if ($this->delayedStopEnabled) {
			foreach ($frames as $frame) {
				$this->frames[] = $frame;
			}
			$this->delayedStop();
		}
 		else {
 			foreach ($frames as $frame) {
				$this->sendFrame($frame);
			}
			if (isset($this->gc)) {
				$this->gcCheck();
			}
		}
	}

	public function delayedStop() {
		Timer::setTimeout($this->timer, 0.15e6) || $this->timer = setTimeout(function($timer) {
			$this->timer = true;
			$timer->free();
			$this->appInstance->unsubscribe('s2c:' . $this->sessId, [$this, 's2c'], function($redis) {
				foreach ($this->frames as $frame) {
					$this->sendFrame($frame);
				}
				$this->finish();
			});
		}, 0.15e6);
	}


	public function onFinish() {
		$this->appInstance->unsubscribe('s2c:' . $this->sessId, [$this, 's2c']);
		$this->appInstance->unsubscribe('w8in:' . $this->sessId, [$this, 'w8in']);
		$this->timer === null || Timer::remove($this->timer);
		parent::onFinish();
	}

	protected function poll($cb = null) {
		$this->appInstance->subscribe('s2c:' . $this->sessId, [$this, 's2c'], function($redis) use ($cb) {
			$this->appInstance->publish('poll:' . $this->sessId, '', function($redis) use ($cb) {
				if ($redis->result === 0) {
					if (!$this->appInstance->beginSession($this->path, $this->sessId, $this->attrs->server)) {
						$this->header('404 Not Found');
						$this->finish();
						return;
					}
				}
				if ($cb !== null) {
					call_user_func($cb);
				}
			});
		});
	}
	
	protected function acquire($cb) {
		if ($this->appInstance->getLocalSubscribersCount('w8in:' . $this->sessId) > 0) {
			$this->error(2010);
			return;
		}
		$this->appInstance->publish('w8in:' . $this->sessId, '', function($redis) use ($cb) {
			if ($redis->result > 0) {
				$this->error(2010);
				return;
			}
			$this->appInstance->subscribe('w8in:' . $this->sessId, [$this, 'w8in'], function($redis) use ($cb) {
				if ($this->appInstance->getLocalSubscribersCount('w8in:' . $this->sessId) > 1) {
					$this->error(2010);
					return;
				}
				$this->appInstance->publish('w8in:' . $this->sessId, '', function($redis) use ($cb) {
					if ($redis->result > 1) {
						$this->error(2010);
						return;
					}
					call_user_func($cb);
				});
			});
		});
	}

	protected function error($code) {
		$this->sendFrame('c' . json_encode([$code, isset($this->errors[$code]) ? $this->errors[$code] : null]) . "\n");
		$this->finish();
	}

	protected function contentType($type) {
		$this->header('Content-Type: '.$type.'; charset=UTF-8');
	}
	protected function noncache() {
		$this->header('Pragma: no-cache');
		$this->header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
	}

	protected function CORS() {
		if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
			$this->header('Access-Control-Allow-Headers: '.$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']);
		}
		if (isset($_COOKIE['JSESSIONID']) && is_string($_COOKIE['JSESSIONID'])) {
			$this->setcookie('JSESSIONID', $_COOKIE['JSESSIONID'], 0, '/');
		}
	}

	/**
	 * Sets session ID
	 * @param string $val
	 * @return void
	 */
	public function setSessId($val) {
		$this->sessId = $val;
	}
	

	/**
	 * Sets path
	 * @param string $val
	 * @return void
	 */
	public function setPath($val) {
		$this->path = $val;
	}
	
	/**
	 * Sets server ID
	 * @param string $val
	 * @return void
	 */
	public function setServerId($val) {
		$this->serverId = $val;
	}
}
