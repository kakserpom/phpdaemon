<?php

/**
 * @package NetworkServers
 * @subpackage FlashPolicyServer
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
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
			'file'                  =>  getcwd().'/conf/crossdomain.xml',
			'listen'				=> '0.0.0.0',
			'port' 			        => 843,
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
			FS::readfile($this->config->file->value, function($file, $data) use ($pool) {
				$pool->policyData = $data;
				$pool->enable();
			});
		}
	}
	
}

class FlashPolicyServerConnection extends Connection {
	protected $lowMark = 23; // length of "<policy-file-request/>\x00"
	protected $highMark = 23;
	/**
	 * Called when new data received.
	 * @return void
	 */
	public function onRead() {
		if (false === ($pct = $this->readExact($this->lowMark))) {
			return; // not readed yet
		}
		if ($pct === "<policy-file-request/>\x00") {
			if ($this->pool->policyData) {
				$this->write($p = $this->pool->policyData . "\x00");
			} else {
				$this->write("<error/>\x00");
			}
		}
		$this->finish();
	}
	
}
