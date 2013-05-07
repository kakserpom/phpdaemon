<?php
namespace PHPDaemon\Clients\Lock;

/**
 * @package    Applications
 * @subpackage LockClient
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Pool extends \PHPDaemon\Network\Client {
	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return array(
			// default server
			'servers'      => '127.0.0.1',
			// default port
			'port'         => 833,
			// @todo add description
			'prefix'       => '',
			'protologging' => 0
		);
	}

	/**
	 * Runs a job
	 * @param string   Name of job
	 * @param bool     wait. If true - will wait in queue for lock.
	 * @param callback onRun. Job's runtime.
	 * @param callback onSuccess. Called when job successfully done.
	 * @param callback onFailure. Called when job failed.
	 * @return void
	 */
	public function job($name, $wait, $onRun, $onSuccess = NULL, $onFailure = NULL) {
		$name = $this->prefix . $name;
		$this->getConnectionByName($name, function ($conn) use ($name, $wait, $onRun, $onSuccess, $onFailure) {
			if (!$conn->connected) {
				return;
			}
			$conn->pool->jobs[$name] = array($onRun, $onSuccess, $onFailure);
			$conn->writeln('acquire' . ($wait ? 'Wait' : '') . ' ' . $name);
		});
	}

	/**
	 * Sends done-event
	 * @param string Name of job
	 * @return void
	 */
	public function done($name) {
		$this->getConnectionByName($name, function ($conn) use ($name) {
			if (!$conn->connected) {
				return;
			}
			$conn->writeln('done ' . $name);
		});
	}

	/**
	 * Sends failed-event
	 * @param string Name of job
	 * @return void
	 */
	public function failed($name) {
		$this->getConnectionByName($name, function ($conn) use ($name) {
			if (!$conn->connected) {
				return;
			}
			$conn->writeln('failed ' . $name);
		});
	}

	/**
	 * Returns available connection from the pool by name
	 * @param string   Key
	 * @param callback Callback
	 * @return boolean Success.
	 */
	public function getConnectionByName($name, $cb) {
		if (
			($this->dtags_enabled)
			&& (($sp = strpos($name, '[')) !== FALSE)
			&& (($ep = strpos($name, ']')) !== FALSE)
			&& ($ep > $sp)
		) {
			$name = substr($name, $sp + 1, $ep - $sp - 1);
		}

		srand(crc32($name));
		$addr = array_rand($this->servers);
		srand();

		return $this->getConnection($addr, $cb);
	}
}
