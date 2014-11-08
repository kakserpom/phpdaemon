<?php
namespace PHPDaemon\Servers\Lock;

use PHPDaemon\Network\Server;

class Pool extends Server {

	/**
	 * @var array Jobs
	 */
	public $lockState = [];

	/**
	 * @var array Array of connection's state
	 */
	public $lockConnState = [];

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/* [string|array] Listen addresses */
			'listen'         => 'tcp://0.0.0.0',
			
			/* [integer] Listen port */
			'listenport'     => 833,
			
			/* [string] Allowed clients ip list */
			'allowedclients' => '127.0.0.1',
			
			/* [boolean] Disabled by default */
			'enable'         => 0,

			/* [boolean] Logging? */
			'protologging'   => false,
		];
	}
}
