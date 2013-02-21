<?php

/**
 * @package NetworkClients
 * @subpackage IRCClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class IRCClient extends NetworkClient {
	public $identd;
	public $protologging = false;

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			'port'			=> 6667,
		);
	}

	public function onReady() {
		$this->identd = IdentServer::getInstance();
		parent::onReady();
	}
}
