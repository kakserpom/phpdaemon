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
			/* [string|array] Listen addresses */
			'listen'         => 'tcp://0.0.0.0',

			/* [integer] Listen port */
			'port'           => 1080,

			/* [boolean] Authentication required */
			'auth'           => 0,

			/* [string] User name */
			'username'       => 'User',

			/* [string] Password */
			'password'       => 'Password',

			/* [string] Allowed clients ip list */
			'allowedclients' => null,
		];
	}
}
