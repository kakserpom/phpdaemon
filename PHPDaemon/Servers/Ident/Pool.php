<?php
namespace PHPDaemon\Servers\Ident;

use PHPDaemon\NetworkServer;

class Pool extends NetworkServer {

	/* Pairs
	 * @var array ["$local:$foreign" => "$user", ...]
	 */
	protected $pairs = [];

	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			// @todo add description strings
			'listen' => '0.0.0.0',
			'port'   => 113,
		];
	}

	/**
	 * Function handles incoming Remote Procedure Calls
	 * You can override it
	 * @param string Method name.
	 * @param array  Arguments.
	 * @return mixed Result
	 */
	public function RPCall($method, $args) {
		if ($method === 'registerPair') {
			list ($local, $foreign, $user) = $args;
			$this->pairs[$local . ':' . $foreign] = $user;
		}
		elseif ($method === 'unregisterPair') {
			list ($local, $foreign) = $args;
			unset($this->pairs[$local . ':' . $foreign]);
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
