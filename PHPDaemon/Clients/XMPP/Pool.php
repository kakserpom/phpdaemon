<?php
namespace PHPDaemon\Clients\XMPP;

use PHPDaemon\Network\Client;

/**
 * @package    NetworkClients
 * @subpackage XMPPClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Pool extends Client {

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/* [integer] Default port */
			'port' => 5222,
		];
	}
}
