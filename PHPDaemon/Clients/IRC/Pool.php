<?php
namespace PHPDaemon\Clients\IRC;

/**
 * @package    NetworkClients
 * @subpackage IRCClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Pool extends \PHPDaemon\Network\Client {

	/**
	 * @var Pool
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
			/* [integer] Port */
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
