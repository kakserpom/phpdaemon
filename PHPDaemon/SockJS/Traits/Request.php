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
