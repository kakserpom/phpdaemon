<?php
namespace PHPDaemon\SockJS\Methods;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\Core\CallbackWrapper;
use PHPDaemon\Utils\Crypt;

/**
 * @package    Libraries
 * @subpackage SockJS
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Jsonp extends Generic {
	protected $delayedStopEnabled = true;
	protected $contentType = 'application/javascript';
	protected $callbackParamEnabled = true;
	protected $poll = true;
	protected $pollMode = ['one-by-one'];

	/**
	 * Send frame
	 * @param  string $frame
	 * @return void
	 */
	protected function sendFrame($frame) {
		$c = &$this->attrs->get['c'];
		if (!is_string($c)) {
			$this->header('400 Bad Request');
			$this->finish();
			return;
		}
		$this->outputFrame($c . '(' . json_encode($frame, JSON_UNESCAPED_SLASHES). ");\r\n");
		parent::sendFrame($frame);
	}
}

