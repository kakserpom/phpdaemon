<?php

/**
 * @package NetworkServers
 * @subpackage LockServer
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class LockServer extends NetworkServer {
	
	public $lockState = array();      // Jobs
	public $lockConnState = array();  // Array of connection's state

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'listen'         => 'tcp://0.0.0.0',
			// listen port
			'listenport'     => 833,
			// allowed clients ip list
			'allowedclients' => '127.0.0.1',
			// disabled by default
			'enable'         => 0,
			'protologging'   => false,
		);
	}

}
class LockServerConnection extends Connection {
	public $server = FALSE;   // Is this S2S-session?
	public $locks = array();  // State of locks.

	/**
	 * Called when client is trying to acquire lock.	
	 * @param string Name of job.
	 * @param boolean Wait if already acquired?
	 * @return string Result
	 */
	public function acquireLock($name, $wait = FALSE) {
		if (!isset($this->pool->lockState[$name])) {
			$this->pool->lockState[$name] = 1;
			$this->pool->lockConnState[$name] = array($this->connId => 1);
			$this->locks[$name] = 1;

			return 'RUN';
		}

		if ($this->pool->lockState[$name] === 1) {
			if ($wait) {
				$this->pool->lockConnState[$name][$this->connId] = 2;
				$this->locks[$name] = 2;

				return 'WAIT';
			}

			return 'FAILED';
		}
	
		return null;
	}

	/**
	 * Called when client sends done- or failed-event.	
	 * @param string Name of job.
	 * @param string Result.
	 * @return string Result.
	 */
	public function done($name, $result) {
		if (
			isset($this->pool->lockState[$name]) 
			&& ($this->pool->lockState[$name] === 1)
			&& isset($this->pool->lockConnState[$name][$this->connId]) 
			&& ($this->pool->lockConnState[$name][$this->connId] === 1)
		) {
			foreach ($this->pool->lockConnState[$name] as $connId => $state) {
				if (isset($this->pool->list[$connId])) {
					$this->pool->list[$connId]->writeln($result . ' ' . $name);
					unset($this->pool->list[$connId]->locks[$name]);
				}
			}

			unset($this->pool->lockState[$name]);
			unset($this->pool->lockConnState[$name]);
		}
	}

	/**
	 * Event of Connection
	 * @return void
	 */
	public function onFinish() {
		if (Daemon::$config->logevents->value) {
			Daemon::log(__METHOD__ . ' invoked');
		}

		foreach ($this->locks as $name => $status) {
			if ($status === 1) {
				$this->done($name, 'FAILED');
			}
			elseif ($status === 2) {
				unset($this->pool->lockConnState[$name][$this->connId]);
			}
		}

	}

	/**
	 * Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;

		while (($l = $this->gets()) !== FALSE) {
			$l = rtrim($l, "\r\n");
			$e = explode(' ', $l, 2);

			if ($e[0] === 'acquire') {
				$this->writeln($this->acquireLock($e[1]) . ' ' . $e[1]);
			}
			elseif ($e[0] === 'acquireWait') {
				$this->writeln($this->acquireLock($e[1], TRUE) . ' ' . $e[1]);
			}
			elseif ($e[0] === 'done') {
				$this->done($e[1], 'DONE'); 
			}
			elseif ($e[0] === 'failed') {
				$this->done($e[1], 'FAILED');
			}
			elseif ($e[0] === 'quit') {
				$this->finish();
			}
			elseif ($e[0] !== '') {
				$this->writeln('PROTOCOL_ERROR');
			}
			if($this->pool->config->protologging->value) {
				Daemon::log('Lock client --> Lock server: ' . Debug::exportBytes(implode(' ', $e)) . "\n");
			}
		}

		if (
			(strpos($this->buf, "\xff\xf4\xff\xfd\x06") !== FALSE) 
			|| (strpos($this->buf, "\xff\xec") !== FALSE)
		) {
			$this->finish();
		}
	}
}
