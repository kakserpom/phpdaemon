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
class Eventsource extends Generic {
	protected $contentType = 'text/event-stream';
	protected $poll = true;
	protected $pollMode = ['stream'];
	protected $gcEnabled = true;

	/**
	 * Send frame
	 * @param  string $frame
	 * @return void
	 */
	public function sendFrame($frame) {
		$this->outputFrame('data: '.$frame . "\r\n\r\n");
		parent::sendFrame($frame);
	}

	/**
	 * Constructor
	 * @return void
	 */
	public function init() {
		parent::init();
		if ($this->isFinished()) {
			return;
		}
		$this->out("\r\n", false);
	}
}
