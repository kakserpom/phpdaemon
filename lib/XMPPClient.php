<?php

/**
 * @package NetworkClients
 * @subpackage XMPPClient
 *
 * @author Zorin Vasily <maintainer@daemon.io>
 */
class XMPPClient extends NetworkClient {
	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			'port'			=> 5222,
		];
	}

}
