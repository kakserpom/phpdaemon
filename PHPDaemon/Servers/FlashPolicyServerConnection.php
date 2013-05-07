<?php
namespace PHPDaemon\Servers;

use PHPDaemon\Connection;

/**
 * @package    NetworkServers
 * @subpackage FlashPolicyServer
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
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
			}
			else {
				$this->write("<error/>\x00");
			}
		}
		$this->finish();
	}

}