<?php
class RTEPClient extends AppInstance {

	public $client;

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		$this->defaultConfig(array(
			'addr'   => 'tcpstream://127.0.0.1:844',
			'enable' => 0,
		));

		if ($this->config->enable->value) {
			Daemon::log(__CLASS__ . ' up.');

			require_once Daemon::$dir . '/lib/asyncRTEPclient.class.php';

			$this->client = new AsyncRTEPclient;
			$this->client->addServer($this->config->addr->value);
			$this->client->trace = TRUE;
		}
	}
}
