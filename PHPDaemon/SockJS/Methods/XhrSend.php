<?php
namespace PHPDaemon\SockJS\Methods;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\Utils\Crypt;

/**
 * @package    Libraries
 * @subpackage SockJS
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class XhrSend extends Generic {
	protected $contentType = 'text/plain';
	protected $allowedMethods = 'POST';

	/**
	 * Called when request iterated
	 * @return void
	 */
	public function run() {
		if ($this->stage++ > 0) {
			$this->header('500 Too Busy');
			return;
		}
		if ($this->attrs->raw === '') {
			$this->header('500 Internal Server Error');
			echo 'Payload expected.';
			return;
		}
		if (!json_decode($this->attrs->raw, true)) {
			$this->header('500 Internal Server Error');
			echo 'Broken JSON encoding.';
			return;
		}
		$this->appInstance->publish('c2s:' . $this->sessId, $this->attrs->raw, function($redis) {
			if ($redis->result === 0) {
				$this->header('404 Not Found');
			} else {
				$this->header('204 No Content');
			}
			$this->finish();
		});
		$this->sleep(10);
	}
}
