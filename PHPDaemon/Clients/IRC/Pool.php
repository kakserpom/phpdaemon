<?php
namespace PHPDaemon\Clients\IRC;

/**
 * @package    NetworkClients
 * @subpackage IRCClient
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Pool extends \PHPDaemon\Network\Client {
	public $identd;
	public $protologging = false;

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			'port' => 6667,
		];
	}

	public function onReady() {
		$this->identd = \PHPDaemon\Servers\Ident\Pool::getInstance();
		parent::onReady();
	}
}
