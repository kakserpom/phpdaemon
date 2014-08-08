<?php
namespace PHPDaemon\SockJS\Traits;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Exceptions\UndefinedMethodCalled;

/**
 * Contains some base methods
 *
 * @package Libraries
 * @subpackage SockJS
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */

trait Request {
	protected $sessId;
	protected $serverId;
	protected $path;
	protected $maxBytesSent = 0;
	protected $errors = [
		2010 => 'Another connection still open',
	];

	protected $bytesSent = 0;
	protected $gc = false;

	public function w8in($redis) {
	}

	public function gcCheck() {
		if ($this->maxBytesSent > 0 && !$this->gc && $this->bytesSent > $this->maxBytesSent) {
			$this->gc = true;
			$this->appInstance->unsubscribe('s2c:' . $this->sessId, [$this, 's2c'], function($redis) {
				$this->finish();
			});
		}
	}

	/**
	 * Output some data
	 * @param string $s String to out
	 * @param bool $flush
	 * @return boolean Success
	 */
	public function out($s, $flush = true) {
		$this->bytesSent += strlen($s);
		parent::out($s, $flush);
	}

	public function onFinish() {
		$this->appInstance->unsubscribe('s2c:' . $this->sessId, [$this, 's2c']);
		$this->appInstance->unsubscribe('w8in:' . $this->sessId, [$this, 'w8in']);
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
				$this->finish();
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
						$this->finish();
						return;
					}
					call_user_func($cb);
				});
			});
		});
	}

	protected function error($code) {
		$this->out('c' . json_encode([$code, isset($this->errors[$code]) ? $this->errors[$code] : null]) . "\n");
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
