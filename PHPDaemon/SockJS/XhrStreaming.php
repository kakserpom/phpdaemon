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

class XhrStreaming extends Generic {
	use Traits\Request;
	protected $stage = 0;
	protected $fillerSent = false;
	protected $maxBytesSent = 131072;

	public function s2c($redis) {
		list (, $chan, $msg) = $redis->result;
		$frames = json_decode($msg, true);
		if (!is_array($frames) || !sizeof($frames)) {
			return;
		}
		if (!$this->fillerSent) {
			$this->out(str_repeat('h', 2048) . "\n");
			$this->fillerSent = true;
		}
		foreach ($frames as $frame) {
			$this->out($frame . "\n");
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
		$this->contentType('application/json');
		$this->noncache();
		$this->acquire(function() {
			$this->poll();
		});
		$this->sleep(300);
	}
}
