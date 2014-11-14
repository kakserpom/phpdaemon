<?php
namespace PHPDaemon\Servers\Socks;

class SlaveConnection extends Connection {

	protected $client;
	protected $lowMark = 2;
	protected $highMark = 32768;

	/**
	 * Set client
	 * @param  SocksServerConnection $client
	 * @return void
	 */
	public function setClient($client) {
		$this->client = $client;
	}

	/**
	 * Called when new data received.
	 * @return void
	 */
	public function onRead() {
		if (!$this->client) {
			return;
		}
		do {
			$this->client->writeFromBuffer($this->bev->input, $this->bev->input->length);
		} while ($this->bev->input->length > 0);
	}

	/**
	 * Event of Connection
	 * @return void
	 */
	public function onFinish() {
		if (isset($this->client)) {
			$this->client->finish();
		}
		unset($this->client);
	}
}
