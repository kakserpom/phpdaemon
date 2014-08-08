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

	public function init() {
		parent::init();
		if ($this->isFinished()) {
			return;
		}
		if (!isset($_POST['d']) || !is_string($_POST['d'])) {
			$this->header('400 Bad Request');
			return;
		}
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		$this->noncache();

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
