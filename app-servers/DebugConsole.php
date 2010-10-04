<?php
class DebugConsole extends AsyncServer {

	public $sessions = array(); // Active sessions

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		$this->defaultConfig(array(
			'listen'     => 'tcp://0.0.0.0',
			'listenport' => 8818,
			'passphrase' => 'secret',
			'enable'     => 0,
		));

		if ($this->config->enable->value) {
			Daemon::log(__CLASS__ . ' up.');

			$this->bindSockets(
				$this->config->listen->value,
				$this->config->listenport->value
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
		$this->sessions[$connId] = new DebugConsoleSession($connId, $this);
	}
}

class DebugConsoleSession extends SocketSession {

	/**
	 * Are we authorized?
	 * @var boolean
	 */
	private $auth = false;

	/**
	 * How much time to try before disconnect
	 * @var integer
	 */
	private $authTries = 3;

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		$this->write('Welcome to the debug console for phpDaemon.

Please enter the password or type "exit": ');
	}

	/**
	 * Disconnecting
	 */
	private function disconnect() {
		$this->writeln('Disconnecting...');
		$this->finish();
	}

	/**
	 * Let's check the password
	 * @param $pass string Password
	 * @return boolean
	 */
	private function checkPassword($pass = '') {
		if ($pass != $this->appInstance->config->passphrase->value) {
			--$this->authTries;
			
			if (0 === $this->authTries) {
				$this->disconnect();
			} else {
				sleep(2);
				$this->write('Wrong password. Please, try again: ');
			}
		} else {
			$this->writeln('You are authorized
');
			$this->auth = true;
		}
	}

	/**
	 * Run the command
	 * @param $command string Command to execute
	 * @param $argument string Argument
	 * @return void
	 */
	private function processCommand($command = '', $argument = '') {
		switch ($command) {
			case 'help':
				$this->writeln('
Debug console for phpDaemon.
Allowed commands:

help	eval	exit
');
				break;
			case 'ping':
				$this->writeln('pong');
				break;
			case 'eval':
				ob_start();
				eval($argument);
				$out = ob_get_contents();
				ob_end_clean();
				$this->writeln($out);
				break;
			default:
				$this->writeln('Unknown command "' . $command . '".
Type "help" to get the list of allowed commands.');
		}

	}

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
			$cmd = trim(strtolower($e[0]));
			$arg = isset($e[1]) ? $e[1] : '';

			if (in_array($cmd, array('quit', 'exit'))) {
				$this->disconnect();
			} 
			elseif (!$this->auth) {
				$this->checkPassword($e[0]);
			} else {
				$this->processCommand($cmd, $arg);
			}
		}

		if ($finish) {
			$this->finish();
		}
	}
}
