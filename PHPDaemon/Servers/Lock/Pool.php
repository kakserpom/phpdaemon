<?php
namespace PHPDaemon\Servers\Lock;

use PHPDaemon\Network\Server;

class Pool extends Server {

	/**
	 * Jobs
	 * @var array
	 */
	public $lockState = [];
	/**
	 * Array of connection's state
	 * @var array
	 */
	public $lockConnState = [];

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			// listen to
			'listen'         => 'tcp://0.0.0.0',
			// listen port
			'listenport'     => 833,
			// allowed clients ip list
			'allowedclients' => '127.0.0.1',
			// disabled by default
			'enable'         => 0,
			'protologging'   => false,
		];
	}

}
