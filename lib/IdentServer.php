<?php

/**
 * @package NetworkServers
 * @subpackage IdentServer
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class IdentServer extends NetworkServer {

	/* Pairs
	 * @var hash ["$local:$foreign" => "$user", ...]
	 */
	protected $pairs = [];

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

	/* Register pair
	 * @param integer Local
	 * @param integer Foreign
	 * @param string  User
	 * @return void
	 */

	public function registerPair($local, $foreign, $user) {
		$this->appInstance->broadcastCall('registerPair', [
				$local,
				$foreign,
				is_array($user) ? implode(' : ', $user) : $user
		]);
	}

	/* Unregister pair
	 * @param integer Local
	 * @param integer Foreign
	 * @return void
	 */
	public function unregisterPair($local, $foreign) {
		$this->appInstance->broadcastCall('unregisterPair', [$local, $foreign]);
	}

	/* Find pair
	 * @param integer Local
	 * @param integer Foreign
	 * @return string User
	 */
	public function findPair($local, $foreign) {
		$k = $local . ':' . $foreign;
		return
			isset($this->pairs[$k])
		 	? $this->pairs[$k]
		 	: false;
	}
}

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
