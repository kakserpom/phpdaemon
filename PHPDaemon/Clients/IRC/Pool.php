<?php
namespace PHPDaemon\Clients\IRC;

/**
 * @package    NetworkClients
 * @subpackage IRCClient
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Pool extends \PHPDaemon\Network\Client {
	/**
	 * @var
	 */
	public $identd;
	/**
	 * @var bool
	 */
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

	/**
	 * @TODO DESCR
	 */
	public function onReady() {
		$this->identd = \PHPDaemon\Servers\Ident\Pool::getInstance();
		parent::onReady();
	}
}
