<?php
namespace PHPDaemon\Servers\FlashPolicy;

use PHPDaemon\Core\Daemon;
use PHPDaemon\FS\FileSystem;
use PHPDaemon\Network\Server;

class Pool extends Server {

	/**
	 * @var string Cached policy file contents
	 */
	public $policyData;

	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/* [string|array] Listen addresses */
			'listen' => '0.0.0.0',

			/* [integer] Listen port */
			'port'   => 843,

			/* [string] Path to crossdomain.xml file */
			'file'   => getcwd() . '/conf/crossdomain.xml',
		];
	}

	/**
	 * Called when worker is ready
	 * @return void
	 */
	public function onReady() {
		$this->onConfigUpdated();
	}

	/**
	 * Called when worker is going to update configuration
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
