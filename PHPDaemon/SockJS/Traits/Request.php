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
	protected $errors = [
		2010 => 'Another connection still open',
	];

	protected function acquire($cb) {
		$this->appInstance->publish('w8in:' . $this->sessId, '', function($redis) use ($cb) {
			if ($redis->result > 0) {
				$this->error(2010);
				$this->finish();
				return;
			}
			if ($this->appInstance->getLocalSubscribersCount('w8in:' . $this->sessId) > 0) {
				$this->error(2010);
				return;
			}
			$this->appInstance->subscribe('w8in:' . $this->sessId, [$this, 'w8in'], function($redis) use ($cb) {
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
