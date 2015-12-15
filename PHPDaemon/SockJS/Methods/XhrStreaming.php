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
class XhrStreaming extends Generic {
	protected $gcEnabled = true;
	protected $contentType = 'application/javascript';
	protected $fillerEnabled = true;
	protected $poll = true;
	protected $pollMode = ['stream'];
	protected $allowedMethods = 'POST';

	/**
	 * afterHeaders
	 * @return void
	 */
	public function afterHeaders() {
		$this->sendFrame(str_repeat('h', 2048));
		$this->bytesSent = 0;
	}

	/**
	 * Send frame
	 * @param  string $frame
	 * @return void
	 */
	protected function sendFrame($frame) {
		$this->outputFrame($frame . "\n");
		parent::sendFrame($frame);
	}
}
