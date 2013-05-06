<?php
namespace PHPDaemon\Clients;

/**
 * @package    NetworkClients
 * @subpackage IRCClient
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class IRCClient extends NetworkClient {
	public $identd;
	public $protologging = false;

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return array(
			'port' => 6667,
		);
	}

	public function onReady() {
		$this->identd = IdentServer::getInstance();
		parent::onReady();
	}
}
