<?php
namespace PHPDaemon\SockJS;
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

class Eventsource extends Generic {
	use Traits\Request;
	protected $stage = 0;
	protected $maxBytesSent = 131072;
	public function s2c($redis) {
		list (, $chan, $msg) = $redis->result;
		$frames = json_decode($msg, true);
		if (!is_array($frames) || !sizeof($frames)) {
			return;
		}
		foreach ($frames as $frame) {
			$this->out('data: '.$frame . "\n\n");
		}

		$this->gcCheck();
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		if ($this->stage++ > 0) {
			$this->sleep(300);
			return;
		}
		$this->CORS();
		$this->contentType('text/event-stream');
		$this->noncache();
		$this->out("\n", false);
		$this->acquire(function() {
			$this->poll();
		});
		$this->sleep(300);
	}
}
