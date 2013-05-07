<?php
namespace PHPDaemon\Servers;

use PHPDaemon\NetworkServer;

class LockServer extends NetworkServer {

	public $lockState = array(); // Jobs
	public $lockConnState = array(); // Array of connection's state

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'listen'         => 'tcp://0.0.0.0',
			// listen port
			'listenport'     => 833,
			// allowed clients ip list
			'allowedclients' => '127.0.0.1',
			// disabled by default
			'enable'         => 0,
			'protologging'   => false,
		);
	}

}
