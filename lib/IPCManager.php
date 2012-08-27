<?php

/**
 * @package Applications
 * @subpackage IPCManager
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class IPCManager extends AsyncServer {

	public $sessions = array();  // Active sessions

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'mastersocket'     => 'unix:/tmp/phpDaemon-master-%x.sock',
			'mastersocketport' => 0,
		);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if (Daemon::$process instanceof Daemon_MasterThread)
		{
			$this->bindSockets(
				sprintf($this->config->mastersocket->value, crc32(Daemon::$config->pidfile->value)),
				$this->config->mastersocketport->value
			);
			$this->enableSocketEvents();
		}
	}
	
	/**
	 * Called when the worker is ready to go
	 * @todo -> protected?
	 * @return void
	 */
	public function onReady()	{
		if (Daemon::$process instanceof Daemon_WorkerThread)
		{
			$this->sessions = array();
			$this->getConnection();
		}
	}
  public function getConnection()
  {
		if (sizeof($this->sessions)) {return current($this->sessions);}
		
		$connId = $this->connectTo(sprintf($this->config->mastersocket->value, crc32(Daemon::$config->pidfile->value)), $this->config->mastersocketport->value);
		if (!$connId) {
			return;
		}
		
		return $this->sessions[$connId] = new IPCManagerWorkerSession($connId, $this);

  }
  
  public function sendPacket($packet) {
		if ($c = $this->getConnection()) {
			$c->sendPacket($packet);
		}
  }
  
  
  public function sendBroadcastCall($appInstance, $method, $args = array(), $cb = null) {
		if ($c = $this->getConnection()) {
			
			$c->sendPacket(array(
					'op' => 'broadcastCall',
					'appfullname' => $appInstance,
					'method' => $method,
					'args' => $args
			));
		}
  }
  
		
		
	/**
	 * Called when new connection is accepted
	 * @param integer Connection's ID
	 * @param string Address of the connected peer
	 * @return void
	 */
	protected function onAccepted($connId, $addr) {
		$this->sessions[$connId] = new IPCManagerMasterSession($connId, $this);
	}
	
}

class IPCManagerMasterSession extends SocketSession {

	public $spawnid;

	/**
	 * Called when the session constructed
	 * @return void
	 */
	public function init() {
		
	}
	
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
class IPCManagerWorkerSession extends SocketSession {

	/**
	 * Called when the session constructed
	 * @return void
	 */
	public function init() {
		
		//Daemon::log(Debug::backtrace());
		$this->sendPacket(array(
			'op' => 'start',
			'pid' => Daemon::$process->pid,
			'spawnid' => Daemon::$process->spawnid)
		);
	
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
		
		while (($l = $this->gets()) !== FALSE) {
			$this->onPacket(json_decode($l,TRUE));
		}
	}
}
