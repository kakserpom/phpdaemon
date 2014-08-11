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

class Eventsource extends Generic {
	protected $contentType = 'text/event-stream';
	protected $poll = true;
	protected $pollMode = ['stream'];
	protected $gcEnabled = true;

	public function sendFrame($frame) {
		$this->outputFrame('data: '.$frame . "\r\n\r\n");
		parent::sendFrame($frame);
	}

	public function init() {
		parent::init();
		if ($this->isFinished()) {
			return;
		}
		$this->out("\r\n", false);
	}
}
