<?php
namespace PHPDaemon\Servers\Socks;

use PHPDaemon\Network\Server;

class Pool extends Server {

	/**
	 * Setting default config options
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			// listen to
			'listen'         => 'tcp://0.0.0.0',
			// default port
			'port'           => 1080,
			// authentication required
			'auth'           => 0,
			// user name
			'username'       => 'User',
			// password
			'password'       => 'Password',
			// allowed clients ip list
			'allowedclients' => null,
		];
	}
}
