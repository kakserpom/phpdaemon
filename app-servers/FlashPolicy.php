<?php

/**
 * @package Applications
 * @subpackage FlashPolicy
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class FlashPolicy extends AppInstance {

	public $pool;				 // ConnectionPool
	public $policyData;          // Cached policy-file.

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'listen'     => 'tcp://0.0.0.0',
			// listen port
			'listenport' => 843,
			// crossdomain file path
			'file'       => 'crossdomain.xml',
			// disabled by default
			'enable'     => 0
		);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->config->enable->value) {
			$this->pool = new ConnectionPool(array(
					'connectionClass' => 'FlashPolicyConnection',
					'listen' => $this->config->listen->value,
					'listenport' => $this->config->listenport->value
			));
			$this->onConfigUpdated();
		}
	}
	
	/**
	 * Called when the worker is ready to go
	 * @todo -> protected?
	 * @return void
	 */
	public function onReady() {
		if (isset($this->pool)) {
			$this->pool->enable();
		}
	}
	/**
	 * Called when application instance is going to shutdown
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown() {
		return $this->pool->onShutdown();
	}

	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		$this->policyData = file_get_contents($this->config->file->value, true);
	}
	
}

class FlashPolicyConnection extends Connection {
	protected $initialLowMark = 23; // length of '<policy-file-request/>\x00'
	protected $initialHighMark = 23;
	/**
	 * Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		if ($buf === "<policy-file-request/>\x00") {
			if ($this->appInstance->policyData) {
				$this->write($this->appInstance->policyData . "\x00");
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
