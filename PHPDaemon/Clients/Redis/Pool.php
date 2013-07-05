<?php
namespace PHPDaemon\Clients\Redis;

use PHPDaemon\Core\CallbackWrapper;
use PHPDaemon\Network\Client;

/**
 * @package    NetworkClients
 * @subpackage RedisClient
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Pool extends Client {

	/**
	 * Subcriptions
	 * @var array
	 */
	protected $subscribeCb = [];

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
			'servers'        => 'tcp://127.0.0.1',

			/**
			 * Default port
			 * @var integer
			 */
			'port'           => 6379,

			/**
			 * Maximum connections per server
			 * @var integer
			 */
			'maxconnperserv' => 32,
		];
	}

	/**
	 * Magic __call.
	 * @method $name 
	 * @param string $name Command name
	 * @param array $args
	 * @usage $ .. Command-dependent set of arguments ..
	 * @usage $ [callback Callback. Optional.
	 * @example  $redis->lpush('mylist', microtime(true));
	 * @return void
	 */
	public function __call($name, $args) {
		$onResponse = null;
		if (($e = end($args)) && (is_array($e) || is_object($e)) && is_callable($e)) {
			$onResponse = array_pop($args);
		}
		reset($args);
		$name = strtoupper($name);
		array_unshift($args, $name);
		$s = sizeof($args);
		$r = '*' . $s . "\r\n";
		foreach ($args as $arg) {
			$r .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
		}
		$this->requestByServer($server = null, $r, $onResponse);

		// PUB/SUB handling
		if (($name === 'SUBSCRIBE') || ($name === 'PSUBSCRIBE')) {
			for ($i = 1; $i < $s; ++$i) {
				$arg = $args[$i];
				// @TODO: check if $onResponse already in subscribeCb[$arg]?
				$this->subscribeCb[$arg][] = CallbackWrapper::wrap($onResponse);
			}
		}

		if (($name === 'UNSUBSCRIBE') || ($name === 'PUNSUBSCRIBE')) {
			for ($i = 1; $i < $s; ++$i) {
				$arg = $args[$i];
				if (isset($this->subscribeCb[$arg])) {
					unset($this->subscribeCb[$arg]);
				}
			}
		}
	}
}
