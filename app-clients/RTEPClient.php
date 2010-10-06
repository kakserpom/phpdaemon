<?php
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
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->config->enable->value) {
			Daemon::log(__CLASS__ . ' up.');

			require_once Daemon::$dir . '/lib/asyncRTEPclient.class.php';

			$this->client = new AsyncRTEPclient;
			$this->client->addServer($this->config->addr->value);
			$this->client->trace = TRUE;
		}
	}
}
