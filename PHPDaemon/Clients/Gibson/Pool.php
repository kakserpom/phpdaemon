<?php
namespace PHPDaemon\Clients\Gibson;


use PHPDaemon\Core\Daemon;

class Pool extends \PHPDaemon\Network\Client {

	protected  $opCodes = [
		'set' => 1,
		'ttl' => 2,
		'get' => 3,
		'del' => 4,
		'inc' => 5,
		'dec' => 6,
		'lock' => 7,
		'unlock' => 8,
		'mset' => 9,
		'mttl' => 10,
		'mget' => 11,
		'mdel' => 12,
		'minc' => 13,
		'mdec' => 14,
		'mlock' => 15,
		'munlock' => 16,
		'count' => 17,
		'stats' => 18,
		'ping' => 19,
		'sizeof' => 20,
		'msizeof' => 21,
		'encof' => 22,
		'end' => 255,
	];

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/**
			 * Default servers
			 * @var string|array
			 */
			'servers'        => 'unix:///var/run/gibson.sock',

			/**
			 * Default port
			 * @var integer
			 */
			'port'           => 10128,

			/**
			 * Maximum connections per server
			 * @var integer
			 */
			'maxconnperserv' => 32
		];
	}


	public function __call($name, $args) {
		$name = strtolower($name);
		if (!isset($this->opcodes[$name])) {
			return false;
		}
		$code = $this->opcodes[$name];
		$onResponse = null;
		if (($e = end($args)) && (is_array($e) || is_object($e)) && is_callable($e)) {
			$onResponse = array_pop($args);
		}
		$data = implode(' ', $args);
		$qLen = strlen($data) + 2;
		$r = pack('ls', $qLen, $opcode) . $data;
		$this->requestByServer($server = null, $r, $onResponse);
	}
}
