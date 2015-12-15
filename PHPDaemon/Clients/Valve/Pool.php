<?php
namespace PHPDaemon\Clients\Valve;

/**
 * @package    NetworkClients
 * @subpackage HLClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 * @link       https://developer.valvesoftware.com/wiki/Server_queries
 */
class Pool extends \PHPDaemon\Network\Client {
	const A2S_INFO                     = "\x54";
	const S2A_INFO                     = "\x49";
	const S2A_INFO_SOURCE              = "\x6d";
	const A2S_PLAYER                   = "\x55";
	const S2A_PLAYER                   = "\x44";
	const A2S_SERVERQUERY_GETCHALLENGE = "\x57";
	const S2A_SERVERQUERY_GETCHALLENGE = "\x41";
	const A2A_PING                     = "\x69";
	const S2A_PONG                     = "\x6A";

	/**
	 * Sends a request
	 * @param  string   $addr Address
	 * @param  string   $type Type of request
	 * @param  string   $data Data
	 * @param  callable $cb Callback
	 * @callback $cb ( )
	 * @return void
	 */
	public function request($addr, $type, $data, $cb) {
		$e = explode(':', $addr);
		$this->getConnection('valve://[udp:' . $e[0] . ']' . (isset($e[1]) ? ':' . $e[1] : '') . '/', function ($conn) use ($cb, $addr, $data, $name) {
			if (!$conn->connected) {
				call_user_func($cb, $conn, false);
				return;
			}
			$conn->request($type, $data, $cb);
		});
	}

	/**
	 * Sends echo-request
	 * @param  string   $addr Address
	 * @param  callable $cb   Callback
	 * @callback $cb ( )
	 * @return void
	 */
	public function ping($addr, $cb) {
		$e = explode(':', $addr);
		$this->getConnection('valve://[udp:' . $e[0] . ']' . (isset($e[1]) ? ':' . $e[1] : '') . '/ping', function ($conn) use ($cb) {
			if (!$conn->connected) {
				call_user_func($cb, $conn, false);
				return;
			}
			$mt = microtime(true);
			$conn->request('ping', null, function ($conn, $success) use ($mt, $cb) {
				call_user_func($cb, $conn, $success ? (microtime(true) - $mt) : false);
			});
		});
	}

	/**
	 * Sends a request of type 'info'
	 * @param  string   $addr Address
	 * @param  callable $cb   Callback
	 * @callback $cb ( )
	 * @return void
	 */
	public function requestInfo($addr, $cb) {
		$this->request($addr, 'info', null, $cb);
	}

	/**
	 * Sends a request of type 'players'
	 * @param  string   $addr Address
	 * @param  callable $cb   Callback
	 * @callback $cb ( )
	 * @return void
	 */
	public function requestPlayers($addr, $cb) {
		$this->request($addr, 'challenge', null, function ($conn, $result) use ($cb) {
			if (is_array($result)) {
				$cb($conn, $result);
				return;
			}
			$conn->request('players', $result, $cb);
		});
	}

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/* [string|array] Default servers */
			'servers'        => '127.0.0.1',

			/* [integer] Default port */
			'port'           => 27015,

			/* [integer] Maximum connections per server */
			'maxconnperserv' => 32,
		];
	}
}
