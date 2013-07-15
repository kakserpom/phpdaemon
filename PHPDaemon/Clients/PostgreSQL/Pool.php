<?php
namespace PHPDaemon\Clients\PostgreSQL;
class Pool extends \PHPDaemon\Network\Client {
	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			// default server
			'server'       => 'tcp://root@127.0.0.1',
			// default port
			'port'         => 5432,
			// @todo add description
			'protologging' => 0,
			// disabled by default
			'enable'       => 0
		];
	}
}