<?php
class MySQLProxy extends AsyncServer {

	public $sessions = array();

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		Daemon::addDefaultSettings(array(
			'mod' . $this->modname . 'upserver'     => '127.0.0.1:3306',
			'mod' . $this->modname . 'listen'       => 'tcp://0.0.0.0',
			'mod' . $this->modname . 'listenport'   => 3307,
			'mod' . $this->modname . 'protologging' => 0,
			'mod' . $this->modname . 'enable'       => 0,
		));

		if (Daemon::$settings['mod' . $this->modname . 'enable']) {
			Daemon::log(__CLASS__ . ' up.');

			$this->bindSockets(
				Daemon::$settings['mod' . $this->modname . 'listen'],
				Daemon::$settings['mod' . $this->modname . 'listenport']
			);
		}
	}
	
	/**
	 * @method onAccepted
	 * @description Called when new connection is accepted.
	 * @param integer Connection's ID.
	 * @param string Address of the connected peer.
	 * @return void
	 */
	public function onAccepted($connId, $addr) {
		$this->sessions[$connId] = new MySQLProxySession($connId, $this);
		return TRUE;
	}
}

class MySQLProxySession extends SocketSession {

	public $upstream;

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		$e = explode(':', Daemon::$settings['mod' . $this->appInstance->modname . 'upserver']);

		$connId = $this->appInstance->connectTo($e[0], $e[1]);

		$this->upstream = $this->appInstance->sessions[$connId] = new MySQLProxyUpserverSession($connId,$this->appInstance);
		$this->upstream->downstream = $this;
	}

	/**
	 * @method stdin
	 * @description Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		// from client to mysqld.
		if (Daemon::$settings['mod' . $this->appInstance->modname . 'protologging']) {
			Daemon::log('MySQLProxy: Client --> Server: ' . Daemon::exportBytes($buf) . "\n\n");
		}

		$this->upstream->write($buf);
	}

	/**
	 * @method onFinish
	 * @description Event of SocketSession (asyncServer).
	 * @return void
	 */
	public function onFinish() {
		$this->upstream->finish();
	}
}

class MySQLProxyUpserverSession extends SocketSession {

	public $downstream;

	/**
	 * @method stdin
	 * @description Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		// from mysqld to client.
		if (Daemon::$settings['mod' . $this->appInstance->modname . 'protologging']) {
			Daemon::log('MysqlProxy: Server --> Client: ' . Daemon::exportBytes($buf) . "\n\n");
		}

		$this->downstream->write($buf);
	}

	/**
	 * @method onFinish
	 * @description Event of SocketSession (asyncServer).
	 * @return void
	 */
	public function onFinish() {
		$this->downstream->finish();
	}
}
