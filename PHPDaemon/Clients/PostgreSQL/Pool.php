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
			/* [array|string] default server */
			'server'       => 'tcp://root@127.0.0.1',
			
			/* [integer] default port */
			'port'         => 5432,
			
			/* [integer] @todo */
			'protologging' => 0,
			
			/* [integer] disabled by default */
			'enable'       => 0
		];
	}
}
