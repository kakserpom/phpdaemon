<?php

/**
 * @package Applications
 * @subpackage RTEPClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class RTEPClient extends AppInstance {

	public $client;
	
	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// default stream address
			'addr'   => 'tcpstream://127.0.0.1:844',
			// disabled by default
			'enable' => 0
		);
	}

	/**
	 * Constructor
	 * @return void
	 */
	public function init() {
		if ($this->config->enable->value) {
			require_once 'lib/asyncRTEPclient.class.php';

			$this->client = new AsyncRTEPclient;
			$this->client->addServer($this->config->addr->value);
			$this->client->trace = TRUE;
		}
	}
}
