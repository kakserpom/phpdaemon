<?php
namespace PHPDaemon\Servers\DebugConsole;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Network\Server;

class Pool extends Server {

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/* [string|array] Listen addresses */
			'listen'     => 'tcp://127.0.0.1',

			/* [integer] Listen port */
			'port'       => 8818,

			/* [string] Password for auth */
			'passphrase' => 'secret',
		];
	}

	/**
	 * Constructor
	 * @return void
	 */
	protected function init() {
		Daemon::log('CAUTION: Danger! DebugConsole is up. Potential security breach.');
	}
}
