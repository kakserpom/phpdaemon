<?php
namespace PHPDaemon\SockJS\Methods;
use PHPDaemon\HTTPRequest\Generic;
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

class XhrSend extends Generic {
	use Traits\Request;
	protected $stage = 0;
	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		if ($this->stage++ > 0) {
			return;
		}
		$this->CORS();
		$this->contentType('text/plain');
		$this->noncache();
		$this->appInstance->publish('c2s:' . $this->sessId, $this->attrs->raw, function($redis) {
			if ($redis->result === 0) {
				$this->header('404 Not Found');
			} else {
				$this->header('204 No Content');
			}
			$this->finish();
		});
		$this->sleep(30);
	}
}
