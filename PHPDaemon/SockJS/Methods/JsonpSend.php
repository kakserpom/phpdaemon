<?php
namespace PHPDaemon\SockJS\Methods;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\Utils\Crypt;
/**
 * @package    Libraries
 * @subpackage SockJS
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */

class JsonpSend extends Generic {
	use Traits\Request;
	protected $contentType = 'text/plain';
	protected $allowedMethods = 'POST';

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		$this->noncache();
		if (!isset($_POST['d']) || !is_string($_POST['d'])) {
			$this->header('500 Internal Server Error');
			echo 'Payload expected.';
			return;
		}
		if (!json_decode($this->attrs->raw, true)) {
			$this->header('500 Internal Server Error');
			echo 'Broken JSON encoding.';
			return;
		}
		$this->appInstance->publish('c2s:' . $this->sessId, $_POST['d'], function($redis) {
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
