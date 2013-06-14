<?php
namespace PHPDaemon\IPCManager;

use PHPDaemon\Config;
use PHPDaemon\Core\AppInstance;
use PHPDaemon\Core\Daemon;
use PHPDaemon\IPCManager\MasterPool;
use PHPDaemon\IPCManager\WorkerConnection;
use PHPDaemon\Thread;

class IPCManager extends AppInstance {
	public $pool;
	public $conn;

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'mastersocket' => 'unix:///tmp/phpDaemon-ipc-%x.sock',
		);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->socketurl = sprintf($this->config->mastersocket->value, crc32(Daemon::$config->pidfile->value . "\x00" . Daemon::$config->user->value . "\x00" . Daemon::$config->group->value));
		if (Daemon::$process instanceof Thread\IPC) {
			$this->pool              = MasterPool::getInstance(array('listen' => $this->socketurl));
			$this->pool->appInstance = $this;
			$this->pool->onReady();
		}
	}

	public function updatedWorkers() {
		$perWorker      = 1;
		$instancesCount = [];
		foreach (Daemon::$config as $name => $section) {
			if (
					(!$section instanceof Config\Section)
					|| !isset($section->limitinstances)
			) {

				continue;
			}
			$instancesCount[$name] = 0;
		}
		foreach ($this->pool->workers as $worker) {
			foreach ($worker->instancesCount as $k => $v) {
				if (!isset($instancesCount[$k])) {
					unset($worker->instancesCount[$k]);
					continue;
				}
				$instancesCount[$k] += $v;
			}
		}
		foreach ($instancesCount as $name => $num) {
			$v = Daemon::$config->{$name}->limitinstances->value - $num;
			foreach ($this->pool->workers as $worker) {
				if ($v <= 0) {
					break;
				}
				if ((isset($worker->instancesCount[$name])) && ($worker->instancesCount[$name] < $perWorker)) {
					continue;
				}
				if (!isset($worker->instancesCount[$name])) {
					$worker->instancesCount[$name] = 1;
				}
				else {
					++$worker->instancesCount[$name];
				}
				$worker->sendPacket(array('op' => 'spawnInstance', 'appfullname' => $name));
				--$v;
			}
		}
	}

	/**
	 * Called when application instance is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown($graceful = false) {
		if ($this->pool) {
			return $this->pool->onShutdown();
		}
		return true;
	}

	public function importFile($workerId, $path) {
		if (!isset($this->pool->workers[$workerId])) {
			return false;
		}
		$worker = $this->pool->workers[$workerId];
		$worker->sendPacket(array('op' => 'importFile', 'path' => $path));
		return true;
	}

	public function ensureConnection() {
		$this->sendPacket('');
	}

	public function sendPacket($packet = null) {
		if ($this->conn && $this->conn->isConnected()) {
			$this->conn->sendPacket($packet);
			return;
		}

		$cb = function ($conn) use ($packet) {
			$conn->sendPacket($packet);
		};
		if (!$this->conn) {
			$this->conn = new WorkerConnection(null, null, null);
			$this->conn->connect($this->socketurl);
		}
		$this->conn->onConnected($cb);
	}

	public function sendBroadcastCall($appInstance, $method, $args = array(), $cb = null) {
		$this->sendPacket(array(
							  'op'          => 'broadcastCall',
							  'appfullname' => $appInstance,
							  'method'      => $method,
							  'args'        => $args,
						  ));
	}

	public function sendSingleCall($appInstance, $method, $args = array(), $cb = null) {
		$this->sendPacket(array(
							  'op'          => 'singleCall',
							  'appfullname' => $appInstance,
							  'method'      => $method,
							  'args'        => $args,
						  ));
	}

	public function sendDirectCall($workerId, $appInstance, $method, $args = array(), $cb = null) {
		$this->sendPacket(array(
							  'op'          => 'directCall',
							  'appfullname' => $appInstance,
							  'method'      => $method,
							  'args'        => $args,
							  'workerId'    => $workerId,
						  ));
	}
}
