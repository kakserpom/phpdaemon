<?php

return new RTEPClient;

class RTEPClient extends AppInstance {

	public $client;

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		Daemon::addDefaultSettings(array(
			'mod' . $this->modname . 'addr'   => 'tcpstream://127.0.0.1:844',
			'mod' . $this->modname . 'enable' => 0,
		));

		if (Daemon::$settings['mod' . $this->modname . 'enable']) {
			Daemon::log(__CLASS__ . ' up.');

			require_once Daemon::$dir . '/lib/asyncRTEPclient.class.php';

			$this->client = new AsyncRTEPclient;
			$this->client->addServer(Daemon::$settings[$k = 'mod' . $this->modname . 'addr']);
			$this->client->trace = TRUE;
		}
	}
}
