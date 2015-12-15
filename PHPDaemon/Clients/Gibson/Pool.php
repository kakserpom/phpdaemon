<?php
namespace PHPDaemon\Clients\Gibson;

use PHPDaemon\Exceptions\UndefinedMethodCalled;

/**
 * @package    Clients
 * @subpackage Gibson
 * @protocol http://gibson-db.in/protocol.php
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Pool extends \PHPDaemon\Network\Client {
	/**
	 * @var array Commands
	 */
	protected  $opCodes = [
		'set' => 1,	'ttl' => 2,
		'get' => 3,	'del' => 4,
		'inc' => 5,	'dec' => 6,
		'lock' => 7,	'unlock' => 8,
		'mset' => 9,	'mttl' => 10,
		'mget' => 11,	'mdel' => 12,
		'minc' => 13,	'mdec' => 14,
		'mlock' => 15,	'munlock' => 16,
		'mcount' => 17,	'stats' => 18,
		'ping' => 19,	'meta' => 20,
		'end' => 255,
	];

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/* [string|array] Default servers */
			'servers'        => 'unix:///var/run/gibson.sock',

			/* [integer] Default port */
			'port'           => 10128,

			/* [integer] Maximum connections per server */
			'maxconnperserv' => 32,

			/* [integer] Maximum allowed size of packet */
			'max-allowed-packet' => new \PHPDaemon\Config\Entry\Size('1M'),
		];
	}

	/**
	 * Magic __call
	 * Example:
	 * $gibson->set(3600, 'key', 'value');
	 * $gibson->get('key', function ($conn) {...});
	 * @param  string $name    Command name
	 * @param  array  $args Arguments
	 * @return void
	 */
	public function __call($name, $args) {
		$name = strtolower($name);
		$onResponse = null;
		if (($e = end($args)) && (is_array($e) || is_object($e)) && is_callable($e)) {
			$onResponse = array_pop($args);
		}
		if (!isset($this->opCodes[$name])) {
			throw new UndefinedMethodCalled;
		}
		$data = implode("\x20", $args);
		$this->requestByServer(null, pack('LS', strlen($data) + 2, $this->opCodes[$name]) . $data, $onResponse);
	}

	/**
	 * Is command?
 	 * @param  string $name Command
	 * @return boolean
	 */
	public function isCommand($name) {
		return isset($this->opCodes[strtolower($name)]);
	}
}
