<?php
namespace PHPDaemon\Servers;

use PHPDaemon\Connection;

/**
 * @package    NetworkServers
 * @subpackage IdentServer
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class IdentServerConnection extends Connection {

	/**
	 * EOL
	 * @var string "\n"
	 */
	protected $EOL = "\r\n";

	/**
	 * Default high mark. Maximum number of bytes in buffer.
	 * @var integer
	 */
	protected $highMark = 32;

	/**
	 * Called when new data received.
	 * @return void
	 */
	protected function onRead() {
		while (($line = $this->readline()) !== null) {
			$e = explode(' , ', $line);
			if ((sizeof($e) <> 2) || !ctype_digit($e[0]) || !ctype_digit($e[1])) {
				$this->writeln($line . ' : ERROR : INVALID-PORT');
				$this->finish();
				return;
			}
			$local   = (int)$e[0];
			$foreign = (int)$e[1];
			if ($user = $this->pool->findPair($local, $foreign)) {
				$this->writeln($line . ' : USERID : ' . $user);
			}
			else {
				$this->writeln($line . ' : ERROR : NO-USER');
			}
		}
	}
}