<?php
namespace PHPDaemon\SockJS\TestRelay;

use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Structures\ObjectStorage;
use PHPDaemon\Core\Debug;

/**
 * @package    SockJS
 * @subpackage TestRelay
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Close extends \PHPDaemon\WebSocket\Route {
	/**
	 * Called when the connection is handshaked.
	 * @return void
	 */
	public function onHandshake() {
		$this->client->finish();
	}
}
