<?php
namespace PHPDaemon\Clients;

use PHPDaemon\Daemon;
use PHPDaemon\Debug;

class LockClientConnection extends NetworkClientConnection {

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;

		while (($l = $this->gets()) !== FALSE) {
			$e = explode(' ', rtrim($l, "\r\n"));

			if ($e[0] === 'RUN') {
				if (isset($this->pool->jobs[$e[1]])) {
					call_user_func($this->pool->jobs[$e[1]][0], $e[0], $e[1], $this->pool);
				}
			}
			elseif ($e[0] === 'DONE') {
				if (isset($this->pool->jobs[$e[1]][1])) {
					call_user_func($this->pool->jobs[$e[1]][1], $e[0], $e[1], $this->pool);
				}
			}
			elseif ($e[0] === 'FAILED') {
				if (isset($this->pool->jobs[$e[1]][2])) {
					call_user_func($this->pool->jobs[$e[1]][2], $e[0], $e[1], $this->pool);
				}
			}
			if ($this->pool->config->protologging->value) {
				Daemon::log('Lock client <-- Lock server: ' . Debug::exportBytes(implode(' ', $e)) . "\n");
			}
		}
	}
}