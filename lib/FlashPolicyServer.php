<?php

/**
 * @package NetworkServers
 * @subpackage FlashPolicyServer
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class FlashPolicyServer extends NetworkServer {

	public $policyData;          // Cached policy-file.
	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'file'                  =>  getcwd().'/conf/crossdomain.xml',
			'listen'				=> '127.0.0.1',
			'port' 			        => 843,
		);
	}
	
	public function onReady() {}

	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		parent::onConfigUpdated();
		$app = $this;
		FS::readfile($this->config->file->value, function($file, $data) use ($app) {
			$app->policyData = $data;
			$app->enable();
		});
	}
	
}

class FlashPolicyServerConnection extends Connection {
	protected $lowMark = 23; // length of "<policy-file-request/>\x00"
	protected $highMark = 23;
	/**
	 * Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		if ($buf === "<policy-file-request/>\x00") {
			if ($this->pool->policyData) {
				$this->write($this->pool->policyData . "\x00");
			} else {
				$this->write("<error/>\x00");
			}
			$this->finish();
		}
		else {
			$this->finish();
		}
	}
	
}
