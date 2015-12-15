<?php
namespace PHPDaemon\Clients\ICMP;

use PHPDaemon\Network\Client;

/**
 * @package    Applications
 * @subpackage ICMPClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Pool extends Client {

	/**
	 * Establishes connection
	 * @param  string   $host Address
	 * @param  callable $cb   Callback
	 * @callback $cb ( )
	 */
	public function sendPing($host, $cb) {
		$this->connect('raw://' . $host, function ($conn) use ($cb) {
			$conn->sendEcho($cb);
		});
	}
}
