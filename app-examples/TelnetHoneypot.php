<?php

/**
 * @package NetworkServers
 * @subpackage TelnetHoneypot
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class TelnetHoneypot extends NetworkServer {
	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'listen'                  =>  '0.0.0.0',
			'port'		             => 23,
		);
	}
}

class TelnetHoneypotConnection extends Connection {
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
