<?php
namespace PHPDaemon\Examples;

/**
 * @package    NetworkServers
 * @subpackage TelnetHoneypot
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class TelnetHoneypot extends \PHPDaemon\Network\Server {
	public $connectionClass = '\PHPDaemon\Examples\TelnetHoneypotConnection';
	
	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			// @todo add description strings
			'listen' => '0.0.0.0',
			'port'   => 23,
		];
	}
}

class TelnetHoneypotConnection extends \PHPDaemon\Network\Connection {
	/**
	 * Called when new data received.
	 * @return void
	 */
	public function onRead() {
		while (!is_null($line = $this->readline())) {
			$finish =
				(strpos($line, $s = "\xff\xf4\xff\xfd\x06") !== FALSE)
					|| (strpos($line, $s = "\xff\xec") !== FALSE)
					|| (strpos($line, $s = "\x03") !== FALSE)
					|| (strpos($line, $s = "\x04") !== FALSE);

			$e   = explode(' ', rtrim($line, "\r\n"), 2);

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
			}
			else {
				$this->writeln('Unknown command "' . $cmd . '"');
			}

			if (
				(strlen($line) > 1024)
				|| $finish
			) {
				$this->finish();
			}
		}
	}
}
