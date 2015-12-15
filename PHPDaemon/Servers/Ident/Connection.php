<?php
namespace PHPDaemon\Servers\Ident;

/**
 * @package    NetworkServers
 * @subpackage IdentServer
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Connection extends \PHPDaemon\Network\Connection {

	/**
	 * @var string EOL
	 */
	protected $EOL = "\r\n";

	/**
	 * @var integer Default high mark. Maximum number of bytes in buffer.
	 */
	protected $highMark = 32;

	/**
	 * Called when new data received.
	 * @return void
	 */
	protected function onRead() {
		while (($line = $this->readline()) !== null) {
			$e = explode(' , ', $line);
			if ((sizeof($e) !== 2) || !ctype_digit($e[0]) || !ctype_digit($e[1])) {
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
