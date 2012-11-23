<?php

/**
 * @package Applications
 * @subpackage IPCManager
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */


class IPCManager extends AppInstance {
	public $pool;
	public $conn;	
	
	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'mastersocket'     => 'unix:/tmp/phpDaemon-ipc-%x.sock',
		);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->socketurl = sprintf($this->config->mastersocket->value, crc32(Daemon::$config->pidfile->value));
		if (Daemon::$process instanceof Daemon_IPCThread) {
			$this->pool = IPCManagerMasterPool::getInstance(array('listen' => $this->socketurl));
			$this->pool->appInstance = $this;
			$this->pool->onReady();
		}
	}


	public function updatedWorkers() {
		$perWorker = 1;
		$instancesCount = array();
		foreach (Daemon::$config as $name => $section)
		{
		 if (
			(!$section instanceof Daemon_ConfigSection)
			|| !isset($section->limitinstances)) {
			
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
					if ($v <= 0) {break;}
					if ((isset($worker->instancesCount[$name])) && ($worker->instancesCount[$name] < $perWorker))	{
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
	public function onShutdown() {
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
		if ($this->conn && $this->conn->connected) {
			$this->conn->sendPacket($packet);
			return;
		}

		$cb = function($conn) use ($packet) {
			$conn->sendPacket($packet);
		};
		if (!$this->conn) {
			$this->conn = new IPCManagerWorkerConnection(null, null, null);
			$this->conn->connectTo($this->socketurl);
		}
		$this->conn->onConnected($cb);
 	}

	public function sendBroadcastCall($appInstance, $method, $args = array(), $cb = null) {
		$this->sendPacket(array(
			'op' => 'broadcastCall',
			'appfullname' => $appInstance,
			'method' => $method,
			'args' => $args,
		));
	}
	public function sendSingleCall($appInstance, $method, $args = array(), $cb = null) {
		$this->sendPacket(array(
			'op' => 'singleCall',
			'appfullname' => $appInstance,
			'method' => $method,
			'args' => $args,
		));
	}
	public function sendDirectCall($workerId, $appInstance, $method, $args = array(), $cb = null) {
		$this->sendPacket(array(
			'op' => 'directCall',
			'appfullname' => $appInstance,
			'method' => $method,
			'args' => $args,
			'workerId' => $workerId,
		));
 	}
}
class IPCManagerMasterPool extends NetworkServer {
	public $workers = array();
}
class IPCManagerMasterPoolConnection extends Connection {
	public $timeout = null;
	public $instancesCount = array();

	public $workerId;
	public function onPacket($p) {
		if (!is_array($p)) {
			return;
		}
		if ($p['op'] === 'start') {
			$this->workerId = $p['workerId'];
			$this->pool->workers[$this->workerId] = $this;
			$this->pool->appInstance->updatedWorkers();
		}
		elseif ($p['op'] === 'broadcastCall') {
			$p['op'] = 'call';
			foreach ($this->pool->workers as $worker) {
				$worker->sendPacket($p);
			}
		}
		elseif ($p['op'] === 'directCall') {
			$p['op'] = 'call';
			if (!isset($this->pool->workers[$p['workerId']])) {
				Daemon::$process->log('directCall(). not sent.');
				return;
			}
			$this->pool->workers[$p['workerId']]->sendPacket($p);
		}
		elseif ($p['op'] === 'singleCall') {
			$p['op'] = 'call';
			$sent = false;
			foreach ($this->pool->workers as $worker) {
				$worker->sendPacket($p);
				$sent = true;
				break;
			}
			if (!$sent) {
				Daemon::$process->log('singleCall(). not sent.');
			}
		}
		elseif ($p['op'] === 'addIncludedFiles') {
			foreach ($p['files'] as $file) {
				Daemon::$process->fileWatcher->addWatch($file, $this->workerId);
			}
		}
	}
	
	public function onFinish() {
		unset($this->pool->workers[$this->workerId]);
		$this->pool->appInstance->updatedWorkers();
	}
	
	public function sendPacket($p) {
		$data = serialize($p);
		$this->write(pack('N', strlen($data)) . $data);
	}

	/**
	 * Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;

		start:

		if (strlen($this->buf) < 4) {
			return; // not ready yet
		}

		$u = unpack('N', $this->buf);
		$size = $u[1];
		
		if (strlen($this->buf) < 4 + $size) {
			return; // no ready yet;
		}

		$packet = binarySubstr($this->buf, 4, $size);

		$this->buf = binarySubstr($this->buf, 4 + $size);

		$this->onPacket(unserialize($packet));

		goto start;
	}
}
class IPCManagerWorkerConnection extends Connection {
	public $timeout = null;
	public function onReady() {
		$this->sendPacket(array(
			'op' => 'start',
			'pid' => Daemon::$process->pid,
			'workerId' => Daemon::$process->id)
		);
		parent::onReady();
	}
	public function onPacket($p) {
		if ($p['op'] === 'spawnInstance') {
			$fullname = $p['appfullname'];
			$fullname = str_replace('-', ':', $fullname);
			if (strpos($fullname,':') === false) {
				$fullname .= ':';
			}
			list($app, $name) = explode(':', $fullname, 2);
			Daemon::$appResolver->appInstantiate($app,$name);
		}
		elseif ($p['op'] === 'importFile') {
			if (!Daemon::$config->autoreimport->value) {
				Daemon::$process->sigusr2(); // graceful restart
				return;
			}
			$path = $p['path'];
			Daemon_TimedEvent::add(function($event) use ($path) {
				$self = Daemon::$process;
				
				if (Daemon::supported(Daemon::SUPPORT_RUNKIT_IMPORT)) {
					//Daemon::log('--start runkit_import('.$path.')');
					runkit_import($path, RUNKIT_IMPORT_FUNCTIONS | RUNKIT_IMPORT_CLASSES | RUNKIT_IMPORT_OVERRIDE);
					//Daemon::log('--end runkit_import('.$path.')');
				} else {
					$this->appInstance->log('Cannot import \''.$path.'\': runkit_import is not callable.');
				}
				
				$event->finish();
			}, 5);
		}
		elseif ($p['op'] === 'call') {
			if (strpos($p['appfullname'],':') === false) {
				$p['appfullname'] .= ':';
			}
			list($app, $name) = explode(':', $p['appfullname'], 2);
			
			if ($app = Daemon::$appResolver->getInstanceByAppName($app, $name)) {
				$app->RPCall($p['method'], $p['args']);
			}
		}
	}
	public function sendPacket($p) {	
		if ($p === null) {
			return;
		}
		$data = serialize($p);
		$this->write(pack('N', strlen($data)) . $data);
	}

	/**
	 * Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;

		start:

		if (strlen($this->buf) < 4) {
			return; // not ready yet
		}

		$u = unpack('N', $this->buf);
		$size = $u[1];
		
		if (strlen($this->buf) < 4 + $size) {
			return; // no ready yet;
		}

		$packet = binarySubstr($this->buf, 4, $size);
		$this->buf = binarySubstr($this->buf, 4 + $size);
		$this->onPacket(unserialize($packet));

		goto start;
	}
}
