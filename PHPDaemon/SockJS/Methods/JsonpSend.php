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
class JsonpSend extends Generic {
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
		$this->noncache();
		if (isset($_SERVER['HTTP_CONTENT_TYPE']) && $_SERVER['HTTP_CONTENT_TYPE'] === 'application/x-www-form-urlencoded') {
			if (!isset($_POST['d']) || !is_string($_POST['d']) || !strlen($_POST['d'])) {
				$this->header('500 Internal Server Error');
				echo 'Payload expected.';
				return;
			}
			if (!json_decode($_POST['d'], true)) {
				$this->header('500 Internal Server Error');
				echo 'Broken JSON encoding.';
				return;
			}
			$payload = $_POST['d'];
		}
		else {
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
			$payload = $this->attrs->raw;
		}
		$this->appInstance->publish('c2s:' . $this->sessId, $payload, function($redis) {
			if ($redis->result === 0) {
				$this->header('404 Not Found');
			} else {
				echo 'ok';
			}
			$this->finish();
		});
		$this->sleep(30);
	}
}
