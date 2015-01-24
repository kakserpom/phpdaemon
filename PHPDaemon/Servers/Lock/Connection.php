<?php
namespace PHPDaemon\Servers\Lock;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/**
 * @package    NetworkServers
 * @subpackage LockServer
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Connection extends \PHPDaemon\Network\Connection {
	
	/**
	 * @var bool Is this S2S-session?
	 */
	public $server = FALSE;
	
	/**
	 * @var array State of locks
	 */
	public $locks = [];

	/**
	 * Called when client is trying to acquire lock.
	 * @param  string  $name Name of job.
	 * @param  boolean $wait Wait if already acquired?
	 * @return string        Result
	 */
	public function acquireLock($name, $wait = FALSE) {
		if (!isset($this->pool->lockState[$name])) {
			$this->pool->lockState[$name]     = 1;
			$this->pool->lockConnState[$name] = [$this->connId => 1];
			$this->locks[$name]               = 1;

			return 'RUN';
		}

		if ($this->pool->lockState[$name] === 1) {
			if ($wait) {
				$this->pool->lockConnState[$name][$this->connId] = 2;
				$this->locks[$name]                              = 2;

				return 'WAIT';
			}

			return 'FAILED';
		}

		return null;
	}

	/**
	 * Called when client sends done- or failed-event.
	 * @param  string $name   Name of job.
	 * @param  string $result Result
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
	 * @param  string $buf New data.
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
			if ($this->pool->config->protologging->value) {
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
