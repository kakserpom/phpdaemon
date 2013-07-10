<?php
namespace PHPDaemon\Examples;

/**
 * @package    NetworkServers
 * @subpackage TelnetHoneypot
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class TelnetHoneypot extends \PHPDaemon\Network\Server {
	protected $connectionClass = '\PHPDaemon\Examples\TelnetHoneypotConnection';
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
		}

		if (
				(strlen($this->buf) > 1024)
				|| $finish
		) {
			$this->finish();
		}
	}
}
