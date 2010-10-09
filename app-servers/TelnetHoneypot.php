<?php

/**
 * @package Applications
 * @subpackage TelnetHoneypot
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class TelnetHoneypot extends AsyncServer {

	public $sessions = array(); // Active sessions

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'listen'     => 'tcp://0.0.0.0',
			// listen port
			'listenport' => 23,
			// disabled by default
			'enable'     => 0
		);
	}

	/**
	 * Constructor.
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
		$this->sessions[$connId] = new TelnetSession($connId, $this);
		$this->sessions[$connId]->addr = $addr;
	}
	
}

class TelnetSession extends SocketSession {

	/**
	 * Called when new data received.
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
