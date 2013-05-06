<?php
namespace PHPDaemon\Servers;

class FlashPolicyServer extends NetworkServer {

	/**
	 * Cached policy file contents
	 * @var string
	 */
	public $policyData;

	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'file'   => getcwd() . '/conf/crossdomain.xml',
			'listen' => '0.0.0.0',
			'port'   => 843,
		);
	}

	public function onReady() {
		$this->onConfigUpdated();
	}

	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		parent::onConfigUpdated();
		if (Daemon::$process instanceof Daemon_WorkerThread) {
			$pool = $this;
			FS::readfile($this->config->file->value, function ($file, $data) use ($pool) {
				$pool->policyData = $data;
				$pool->enable();
			});
		}
	}

}
