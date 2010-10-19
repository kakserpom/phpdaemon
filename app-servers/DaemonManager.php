<?php

/**
 * @package Applications
 * @subpackage DaemonManager
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class DaemonManager extends AsyncServer {

	public $sessions = array();  // Active sessions

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'mastersocket'     => 'unix:/tmp/phpDaemon-master.sock',
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
				$this->config->mastersocket->value,
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
		
		$connId = $this->connectTo($this->config->mastersocket->value, $this->config->mastersocketport->value);
		if (!$connId) {
			return;
		}
		
		return $this->sessions[$connId] = new DaemonManagerWorkerSession($connId, $this);

  }
	/**
	 * Called when new connection is accepted
	 * @param integer Connection's ID
	 * @param string Address of the connected peer
	 * @return void
	 */
	protected function onAccepted($connId, $addr) {
		$this->sessions[$connId] = new DaemonManagerMasterSession($connId, $this);
	}
	
}

class DaemonManagerMasterSession extends SocketSession {
	public $spawnid;

	/**
	 * Called when the session constructed
	 * @return void
	 */
	public function init() {
		
	}
	
	public function onPacket($p) {
	
	 if ($p['op'] === 'start')
	 {
	  $this->spawnid = $p['spawnid'];
	  Daemon::$process->workers->threads[$this->spawnid]->connection = $this;
	  Daemon::$process->updatedWorkers();
	 }
	 elseif ($p['op'] == 'addIncludedFiles') {
		foreach ($p['files'] as $file) {
			Daemon::$process->fileWatcher->addWatch($file,$this->spawnid);
		}
	 }
	}
	public function onFinish()
	{
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
			$this->onPacket(json_decode($l,TRUE));
		}
	}
}
class DaemonManagerWorkerSession extends SocketSession {

	/**
	 * Called when the session constructed
	 * @return void
	 */
	public function init() {
		
		$this->sendPacket(array(
			'op' => 'start',
			'pid' => Daemon::$process->pid,
			'spawnid' => Daemon::$process->spawnid)
		);
	
	}
	public function onPacket($p) {
		if ($p['op'] === 'spawnInstance') {
			$fullname = $p['appfullname'];
			if (strpos($fullname,'-') === false) {
				$fullname .= '-';
			}
			list($app, $name) = explode('-', $fullname, 2);
			Daemon::$appResolver->appInstantiate($app,$name);
		}
		elseif ($p['op'] === 'importFile') {
			runkit_import($p['path'], RUNKIT_IMPORT_FUNCTIONS | RUNKIT_IMPORT_CLASSES | RUNKIT_IMPORT_OVERRIDE);
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
