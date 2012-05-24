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
			'file'                  =>  'crossdomain.xml',
			'listen'				=> '127.0.0.1',
			'listen-port'           => 843,
		);
	}
	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		parent::onConfigUpdated();
		$this->policyData = file_get_contents($this->config->file->value, true);
	}
	
}

class FlashPolicyServerConnection extends Connection {
	protected $initialLowMark = 23; // length of "<policy-file-request/>\x00"
	protected $initialHighMark = 23;
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
