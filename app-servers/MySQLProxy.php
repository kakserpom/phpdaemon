<?php

class MySQLProxy extends AsyncServer {

	public $sessions = array();

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// FIXME: add description
			'upserver'     => '127.0.0.1:3306',
			// listen to
			'listen'       => 'tcp://0.0.0.0',
			// listen port
			'listenport'   => 3307,
			// FIXME: add description
			'protologging' => 0,
			// disabled by default
			'enable'       => 0
		);
	}

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->config->enable->value) {
			Daemon::log(__CLASS__ . ' up.');

			$this->bindSockets(
				$this->config->listen->value,
				$this->config->listenport->value
			);
		}
	}
	
	/**
	 * Called when new connection is accepted
	 * @param integer Connection's ID
	 * @param string Address of the connected peer
	 * @return void
	 */
	protected function onAccepted($connId, $addr) {
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
		$e = explode(':', $this->appInstance->config->upserver->value);

		$connId = $this->appInstance->connectTo($e[0], $e[1]);

		$this->upstream = $this->appInstance->sessions[$connId] = new MySQLProxyUpserverSession($connId, $this->appInstance);
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
		if ($this->appInstance->config->protologging->value) {
			Daemon::log('MySQLProxy: Client --> Server: ' . Debug::exportBytes($buf) . "\n\n");
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
		if ($this->appInstance->config->protologging->value) {
			Daemon::log('MysqlProxy: Server --> Client: ' . Debug::exportBytes($buf) . "\n\n");
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
