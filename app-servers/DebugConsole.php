<?php

/**
 * @package Applications
 * @subpackage DebugConsole
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class DebugConsole extends AsyncServer {

	public $pool;

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
			'listenport' => 8818,
			// password to login
			'passphrase' => 'secret',
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
			$this->pool = new ConnectionPool(array(
					'connectionClass' => 'DebugConsoleConnection',
					'listen' => $this->config->listen->value,
					'listenport' => $this->config->listenport->value
			));
			$this->pool->appInstance = $this;
		}
	}
	
	/**
	 * Called when the worker is ready to go
	 * @todo -> protected?
	 * @return void
	 */
	public function onReady() {
		if (isset($this->pool)) {
			$this->pool->enable();
		}
	}

	/**
	 * Called when new connection is accepted
	 * @param integer Connection's ID
	 * @param string Address of the connected peer
	 * @return void
	 */
	protected function onAccepted($connId, $addr) {
		$this->sessions[$connId] = new DebugConsoleSession($connId, $this);
	}
	
}

class DebugConsoleConnection extends Connection {

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
	 * Constructor.
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
	 * @param string Password
	 * @return boolean
	 */
	private function checkPassword($pass = '') {
		if ($pass != $this->pool->appInstance->config->passphrase->value) {
			--$this->authTries;
			
			if (0 === $this->authTries) {
				$this->disconnect();
			} else {
				$this->write('Wrong password. Please, try again: ');
			}
		} else {
			$this->writeln('You are authorized.');
			$this->auth = true;
		}
	}

	/**
	 * Run the command
	 * @param string Command to execute
	 * @param string Argument
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
