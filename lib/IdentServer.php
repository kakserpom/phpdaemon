<?php

/**
 * @package NetworkServers
 * @subpackage IdentServer
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class IdentServer extends NetworkServer {
	public $pairs = [];

	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			// @todo add description strings
			'listen'				=> '0.0.0.0',
			'port' 			        => 113,
		];
	}

	/**
	 * Function handles incoming Remote Procedure Calls
	 * You can override it
	 * @param string Method name.
	 * @param array Arguments.
	 * @return mixed Result
	 */
	public function RPCall($method, $args) {
		if ($method === 'registerPair') {
			list ($local, $foreign, $user) = $args;
			$this->pairs[$local . ':' .$foreign] = $user;
		}
		elseif ($method === 'unregisterPair') {
			list ($local, $foreign) = $args;
			unset($this->pairs[$local . ':' .$foreign]);
		}
	}

	public function registerPair($local, $foreign, $user) {
		$this->appInstance->broadcastCall('registerPair', [$local, $foreign, $user]);
	}
	public function unregisterPair($local, $foreign) {
		$this->appInstance->broadcastCall('unregisterPair', [$local, $foreign]);
	}
	public function findPair($local, $foreign) {
		return
			isset($this->pairs[$local . ':' .$foreign])
		 	? $this->pairs[$local . ':' .$foreign]
		 	: false;
	}
}

class IdentServerConnection extends Connection {
	protected $EOL = "\r\n";
	protected $highMark = 32;
	/**
	 * Called when new data received.
	 * @return void
	 */
	protected function onRead() {
		while (($line = $this->readline()) !== null) {
			$e = explode(' , ', $line);
			if ((sizeof($e) <> 2) || !ctype_digit($e[0]) || !ctype_digit($e[1])) {
				$this->writeln($line. ' : ERROR : INVALID-PORT');
				$this->finish();
				return;
			}
			$local = (int) $e[0];
			$foreign = (int) $e[1];
			if ($user = $this->pool->findPair($local, $foreign)) {
				$this->writeln($line. ' : USERID : ' . $user);
			} else {
				$this->writeln($line. ' : ERROR : NO-USER');	
			}
		}
	}
}
