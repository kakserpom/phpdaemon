<?php
namespace PHPDaemon\Servers\FlashPolicy;

use PHPDaemon\Core\Daemon;
use PHPDaemon\FS\FileSystem;
use PHPDaemon\Network\Server;

class Pool extends Server {

	/**
	 * Cached policy file contents
	 * @var string
	 */
	public $policyData;

	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/**
			 * Path to crossdomain.xml file
			 * @var string
			 */ 
			'file'   => getcwd() . '/conf/crossdomain.xml',

			/* Listen addresses
			 * @var string
			 */ 
			'listen' => '0.0.0.0',

			/* Default port
			 * @var string
			 */ 
			'port'   => 843,
		];
	}

	/**
	 * Called when worker is ready
	 */
	public function onReady() {
		$this->onConfigUpdated();
	}

	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		parent::onConfigUpdated();
		if (Daemon::$process instanceof \PHPDaemon\Thread\Worker) {
			$pool = $this;
			FileSystem::readfile($this->config->file->value, function ($file, $data) use ($pool) {
				$pool->policyData = $data;
				$pool->enable();
			});
		}
	}

}
