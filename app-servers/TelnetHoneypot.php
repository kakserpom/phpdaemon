<?php

return new TelnetHoneypot;

class TelnetHoneypot extends AsyncServer {

	public $sessions = array(); // Active sessions

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		Daemon::$settings += array(
			'mod' . $this->modname . 'listen'     => 'tcp://0.0.0.0',
			'mod' . $this->modname . 'listenport' => 23,
			'mod' . $this->modname . 'enable'     => 0,
		);

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
		$this->sessions[$connId] = new TelnetSession($connId, $this);
		$this->sessions[$connId]->addr = $addr;
	}
}

class TelnetSession extends SocketSession {

	/**
	 * @method stdin
	 * @description Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;
		$finish = 
			(strpos($this->buf, $s = "\xff\xf4\xff\xfd\x06") !== FALSE) 
			|| (strpos($this->buf, $s = "\xff\xec") !== FALSE)
			|| (strpos($this->buf, $s = "\x03") !== FALSE) 
			|| (strpos($this->buf, $s = "\x04") !== FALSE);

		while (($line = $this->gets()) !== FALSE) {
			$e = explode(' ', rtrim($line, "\r\n"), 2);
			$cmd = trim($e[0]);

			if ($cmd === 'ping') {
				$this->writeln('pong');
			}
			elseif (
				($cmd === 'exit') 
				|| ($cmd === 'quit')
			) {
				$this->writeln('Quit');
				$this->finish();
			} else {
				$this->writeln('Unknown command "' . $cmd . '"');
			}
		}

		if (
			(strlen($this->buf) > 1024) 
			|| $finish
		) {
			$this->finish();
		}
	}
}
