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
			'mastersocket'     => 'unix:/tmp/phpDaemon-master-%x.sock',
		);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->socketurl = $this->config->mastersocket->value;
		$this->pool = IPCManagerMasterPool::getInstance(array('listen' => $this->socketurl));
		$this->pool->onReady();
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		$this->sendPacket(array(
			'op' => 'start',
			'pid' => Daemon::$process->pid,
			'spawnid' => Daemon::$process->spawnid)
		);
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


	public function sendPacket($packet) {
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
			'args' => $args
		));
 	}

}
class IPCManagerMasterPool extends NetworkServer {}
class IPCManagerMasterPoolConnection extends Connection {

	public $spawnid;
	public function onPacket($p) {
		if ($p['op'] === 'start') {
			$this->spawnid = $p['spawnid'];
			Daemon::$process->workers->threads[$this->spawnid]->connection = $this;
			Daemon::$process->updatedWorkers();
		}
		elseif ($p['op'] === 'broadcastCall') {
			$p['op'] = 'call';
			foreach (Daemon::$process->workers->threads as $worker) {
				if (isset($worker->connection) && $worker->connection) {
					$worker->connection->sendPacket($p);
				}
			}
		}
		elseif ($p['op'] === 'directCall') {
			$p['op'] = 'call';
			if (!isset(Daemon::$process->workers->threads[$p['spawnid']]->connection)) {
				return;
			}
			Daemon::$process->workers->threads[$p['spawnid']]->connection->sendPacket($p);
		}
		elseif ($p['op'] === 'singleCall') {
			$p['op'] = 'call';
			foreach (Daemon::$process->workers->threads as $worker) {
				if (isset($worker->connection) && $worker->connection) {
					$worker->connection->sendPacket($p);
					break;
				}
			}
		}
		elseif ($p['op'] === 'addIncludedFiles') {
			foreach ($p['files'] as $file) {
				Daemon::$process->fileWatcher->addWatch($file, $this->spawnid);
			}
		}
	}
	
	public function onFinish() {
		unset(Daemon::$process->workers->threads[$this->spawnid]->connection);
		unset($this->appInstance->sessions[$this->connId]);
		Daemon::$process->updatedWorkers();
	}
	
	public function sendPacket($p) {
		$this->writeln(json_encode($p));
	}

	/**
	 * Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;
		
		while (($l = $this->gets()) !== FALSE) {
			$this->onPacket(json_decode($l, TRUE));
		}
	}
}
class IPCManagerWorkerConnection extends Connection {

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
				
			if (strpos($p['appfullname'],'-') === false) {
				$p['appfullname'] .= '-';
			}
			list($app, $name) = explode('-', $p['appfullname'], 2);
			
			if ($app = Daemon::$appResolver->getInstanceByAppName($app,$name)) {
				$app->RPCall($p['method'],$p['args']);
			}
		}
	}
	public function sendPacket($p) {
		
		$this->writeln(json_encode($p));
		
	}

	/**
	 * Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;
		
		while (($l = $this->gets()) !== false) {
			$this->onPacket(json_decode($l, true));
		}
	}
}
