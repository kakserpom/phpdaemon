<?php

/**
 * @package NetworkServers
 * @subpackage FlashPolicy
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class FlashPolicy extends NetworkServer {

	public $pool;				 // ConnectionPool
	public $policyData;          // Cached policy-file.
	public $file = 'crossdomain.xml';
	public $listen = 'tcp://0.0.0.0';
	public $defaultPort = 843;
	public $connectionClass = 'FlashPolicyConnection';

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->onConfigUpdated();
	}
	
	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		$this->policyData = file_get_contents($this->file, true);
	}
	
}

class FlashPolicyConnection extends Connection {
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
