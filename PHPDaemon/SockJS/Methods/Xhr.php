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

class Xhr extends Generic {
	protected $delayedStopEnabled = true;
	protected $contentType = 'application/json';
	protected $poll = true;
	protected function sendFrame($frame) {
		$this->out($frame . "\n");
	}
}
