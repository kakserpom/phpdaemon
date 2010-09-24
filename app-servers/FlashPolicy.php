<?php

return new FlashPolicy;

class FlashPolicy extends AsyncServer {

	public $sessions = array();  // Active sessions
	public $policyData;          // Cached policy-file.

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		Daemon::addDefaultSettings(array(
			'mod' . $this->modname . 'listen'     => 'tcp://0.0.0.0',
			'mod' . $this->modname . 'listenport' => 843,
			'mod' . $this->modname . 'file'       => Daemon::$dir.'/conf/crossdomain.xml',
			'mod' . $this->modname . 'enable'     => 0
		));

		if (Daemon::$settings['mod' . $this->modname . 'enable']) {
			Daemon::log(__CLASS__ . ' up.');

			$this->bindSockets(
				Daemon::$settings['mod' . $this->modname . 'listen'],
				Daemon::$settings['mod' . $this->modname . 'listenport']
			);

			$this->update();
		}
	}

	/**
	 * @method update
	 * @description Called when worker is going to update configuration.
	 * @return void
	 */
	public function update() {
		$this->policyData = file_get_contents(Daemon::$settings['mod' . $this->modname . 'file']);
	}

	/**
	 * @method onAccepted
	 * @description Called when new connection is accepted.
	 * @param integer Connection's ID.
	 * @param string Address of the connected peer.
	 * @return void
	 */
	public function onAccepted($connId, $addr) {
		$this->sessions[$connId] = new FlashPolicySession($connId, $this);
	}
}

class FlashPolicySession extends SocketSession {

	/**
	 * @method stdin
	 * @description Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;
		if (strpos($this->buf, '<policy-file-request/>') !== FALSE) {
			
			if ($this->appInstance->policyData) {
				$this->write($this->appInstance->policyData . "\x00");
			} else {
				$this->write("<error/>\x00");
			}
			$this->finish();
		}
		elseif (
		     (strlen($this->buf) > 64) 
			|| (strpos($this->buf, "\xff\xf4\xff\xfd\x06") !== FALSE) 
			|| (strpos($this->buf, "\xff\xec") !== FALSE)) {
			$this->finish();
		}
	}
}
