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
			'mastersocket'     => 'unix:///tmp/phpDaemon-ipc-%x.sock',
		);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->socketurl = sprintf($this->config->mastersocket->value, crc32(Daemon::$config->pidfile->value . "\x00" . Daemon::$config->user->value . "\x00" . Daemon::$config->group->value));
		if (Daemon::$process instanceof Daemon_IPCThread) {
			$this->pool = IPCManagerMasterPool::getInstance(array('listen' => $this->socketurl));
			$this->pool->appInstance = $this;
			$this->pool->onReady();
		}
	}


	public function updatedWorkers() {
		$perWorker = 1;
		$instancesCount = [];
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

		$cb = function($conn) use ($packet) {
			$conn->sendPacket($packet);
		};
		if (!$this->conn) {
			$this->conn = new IPCManagerWorkerConnection(null, null, null);
			$this->conn->connect($this->socketurl);
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
	public $instancesCount = [];
	protected $timeout = null;
	protected $lowMark  = 4;         // initial value of the minimal amout of bytes in buffer
	protected $highMark = 0xFFFF;  	// initial value of the maximum amout of bytes in buffer
	protected $workerId;
	const STATE_CONTENT = 1;
	protected $packetLength;
	protected function onPacket($p) {
		if (!is_array($p)) {
			return;
		}
		//Daemon::log(Debug::dump($p));;
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
		$data = igbinary_serialize($p);
		$this->write(pack('N', strlen($data)) . $data);
	}

	/**
	 * Called when new data received.
	 * @return void
	 */
	public function onRead() {
		start:
		if ($this->state === self::STATE_ROOT) {
			if (false === ($r = $this->readExact(4))) {
				return; // not ready yet
			}
			$u = unpack('N', $r);
			$this->packetLength = $u[1];
			$this->state = self::STATE_CONTENT;
		}
		if ($this->state === self::STATE_CONTENT) {
			if (false === ($packet = $this->readExact($this->packetLength))) {
				$this->setWatermark($this->packetLength);
				return; // not ready yet
			}
			$this->setWatermark(4);
			$this->state = self::STATE_ROOT;
			$this->onPacket(igbinary_unserialize($packet));
		}
		goto start;
	}
}
class IPCManagerWorkerConnection extends Connection {
	protected $timeout = null;
	protected $lowMark  = 4;         // initial value of the minimal amout of bytes in buffer
	protected $highMark = 0xFFFF;  	// initial value of the maximum amout of bytes in buffer
	const STATE_CONTENT = 1;
	protected $packetLength;
	public function onReady() {
		$this->sendPacket([
			'op' => 'start',
			'pid' => Daemon::$process->getPid(),
			'workerId' => Daemon::$process->getId()
		]);
		parent::onReady();
	}
	protected function onPacket($p) {
		if ($p['op'] === 'spawnInstance') {
			$fullname = $p['appfullname'];
			$fullname = str_replace('-', ':', $fullname);
			if (strpos($fullname,':') === false) {
				$fullname .= ':';
			}
			list($app, $name) = explode(':', $fullname, 2);
			Daemon::$appResolver->appInstantiate($app, $name, true);
		}
		elseif ($p['op'] === 'importFile') {
			if (!Daemon::$config->autoreimport->value) {
				Daemon::$process->gracefulRestart();
				return;
			}
			$path = $p['path'];
			TImer::add(function($event) use ($path) {				
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
		$data = igbinary_serialize($p);
		$this->write(pack('N', strlen($data)) . $data);
	}

	
	/**
	 * Called when new data received.
	 * @return void
	 */
	public function onRead() {
		start:
		if ($this->state === self::STATE_ROOT) {
			if (false === ($r = $this->readExact(4))) {
				return; // not ready yet
			}
			$u = unpack('N', $r);
			$this->packetLength = $u[1];
			$this->state = self::STATE_CONTENT;
		}
		if ($this->state === self::STATE_CONTENT) {
			if (false === ($packet = $this->readExact($this->packetLength))) {
				$this->setWatermark($this->packetLength);
				return; // not ready yet
			}
			$this->setWatermark(4);
			$this->state = self::STATE_ROOT;
			$this->onPacket(igbinary_unserialize($packet));
		}
		goto start;
	}
}
