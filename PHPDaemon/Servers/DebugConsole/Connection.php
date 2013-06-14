<?php
namespace PHPDaemon\Servers\DebugConsole;

/**
 * @package    NetworkServers
 * @subpackage DebungConsole
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Connection extends \PHPDaemon\Network\Connection {

	public $timeout = 5;

	/**
	 * Are we authorized?
	 * @var boolean
	 */
	protected $auth = false;

	/**
	 * How much time to try before disconnect
	 * @var integer
	 */
	protected $authTries = 3;

	/**
	 * Constructor.
	 * @return void
	 */
	public function onReady() {
		$this->write('Welcome to the debug console for phpDaemon.

Please enter the password or type "exit": ');
	}

	/**
	 * Disconnecting
	 */
	protected function disconnect() {
		$this->writeln('Disconnecting...');
		$this->finish();
	}

	/**
	 * Let's check the password
	 * @param string Password
	 * @return boolean
	 */
	protected function checkPassword($pass = '') {
		if ($pass != $this->pool->config->passphrase->value) {
			--$this->authTries;

			if (0 === $this->authTries) {
				$this->disconnect();
			}
			else {
				$this->write('Wrong password. Please, try again: ');
			}
		}
		else {
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
	protected function processCommand($command = '', $argument = '') {
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
			$e   = explode(' ', rtrim($line, "\r\n"), 2);
			$cmd = trim(strtolower($e[0]));
			$arg = isset($e[1]) ? $e[1] : '';

			if (in_array($cmd, array('quit', 'exit'))) {
				$this->disconnect();
			}
			elseif (!$this->auth) {
				$this->checkPassword($e[0]);
			}
			else {
				$this->processCommand($cmd, $arg);
			}
		}

		if ($finish) {
			$this->finish();
		}
	}
}