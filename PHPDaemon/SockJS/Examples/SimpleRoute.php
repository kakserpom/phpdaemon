<?php
namespace PHPDaemon\SockJS\Examples;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

class SimpleRoute extends \PHPDaemon\WebSocket\Route {
	/**
	 * Called when new frame received.
	 * @param string  Frame's contents.
	 * @param integer Frame's type.
	 * @return void
	 */
	public function onFrame($data, $type) {
		D($data);
		if ($data === 'ping') {
			$this->client->sendFrame('pong');
		}
	}

	/**
	 * Uncaught exception handler
	 * @param $e
	 * @return boolean|null Handled?
	 */
	public function handleException($e) {
		$this->client->sendFrame('exception ...');
	}
}
