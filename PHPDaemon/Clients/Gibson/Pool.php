<?php
/**
 * @package    Clients
 * @subpackage Gibson
 *
 * @protocol http://gibson-db.in/protocol.php
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
namespace PHPDaemon\Clients\Gibson;
use PHPDaemon\Exceptions\UndefinedMethodCalled;

class Pool extends \PHPDaemon\Network\Client {

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

			/**
			 * Maximum allowed size of packet
			 * @var integer
			 */
			'max-allowed-packet' => new \PHPDaemon\Config\Entry\Size('1M'),
		];
	}

	/**
	 * Magic __call.
	 * @method $name 
	 * @param string $name Command name
	 * @param array $args
	 * @usage $ .. Command-dependent set of arguments ..
	 * @usage $ [callback Callback. Optional.
	 * @example  $gibson->set(3600, 'key', 'value');
	 * @example  $gibson->get('key', function ($conn) {...});
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
 	 * @param string $name
	 * @return boolean
	 */
	public function isCommand($name) {
		return isset($this->opCodes[strtolower($name)]);
	}
}
