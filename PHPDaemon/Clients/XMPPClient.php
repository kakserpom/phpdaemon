<?php
namespace PHPDaemon\Clients;

use PHPDaemon\NetworkClient;

/**
 * @package    NetworkClients
 * @subpackage XMPPClient
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class XMPPClient extends NetworkClient {
	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			'port' => 5222,
		];
	}

}
