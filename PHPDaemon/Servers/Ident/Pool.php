<?php
namespace PHPDaemon\Servers\Ident;

use PHPDaemon\Network\Server;

class Pool extends Server {

	/**
	 * @var array Pairs ["$local:$foreign" => "$user", ...]
	 */
	protected $pairs = [];

	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/* [string|array] Listen addresses */
			'listen' => '0.0.0.0',

			/* [integer] Listen port */
			'port'   => 113,
		];
	}

	/**
	 * Function handles incoming Remote Procedure Calls
	 * You can override it
	 * @param  string $method Method name.
	 * @param  array  $args   Arguments.
	 * @return void
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

	/**
	 * Register pair
	 * @param  integer $local   Local
	 * @param  integer $foreign Foreign
	 * @param  string  $user    User
	 * @return void
	 */

	public function registerPair($local, $foreign, $user) {
		$this->appInstance->broadcastCall('registerPair', [
			$local,
			$foreign,
			is_array($user) ? implode(' : ', $user) : $user
		]);
	}

	/**
	 * Unregister pair
	 * @param  integer $local   Local
	 * @param  integer $foreign Foreign
	 * @return void
	 */
	public function unregisterPair($local, $foreign) {
		$this->appInstance->broadcastCall('unregisterPair', [$local, $foreign]);
	}

	/**
	 * Find pair
	 * @param  integer $local   Local
	 * @param  integer $foreign Foreign
	 * @return string           User
	 */
	public function findPair($local, $foreign) {
		$k = $local . ':' . $foreign;
		return
				isset($this->pairs[$k])
						? $this->pairs[$k]
						: false;
	}
}
