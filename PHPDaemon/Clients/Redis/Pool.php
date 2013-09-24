<?php
namespace PHPDaemon\Clients\Redis;

use PHPDaemon\Core\CallbackWrapper;
use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Structures\ObjectStorage;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/**
 * @package    NetworkClients
 * @subpackage RedisClient
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Pool extends \PHPDaemon\Network\Client {
	public $servConnSub = [];

	/**
	 * Detaches connection from URL
	 * @param ClientConnection $conn Connection
	 * @param string $url URL
	 * @return void
	 */
	public function detachConnFromUrl(ClientConnection $conn, $url) {
		 parent::detachConnFromUrl($conn, $url);
		 if ($conn->isSubscribed()) {
			unset($this->servConnSub[$url]);
		}
	}

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
	 * @example  $redis->lpush('mylist', microtime(true));
	 * @return void
	 */
	public function __call($cmd, $args) {
		$cb = null;
		if (($e = end($args)) && (is_array($e) || is_object($e)) && is_callable($e)) {
			$cb = array_pop($args);
		}
		reset($args);
		$cmd = strtoupper($cmd);

		if (in_array($cmd, ['SUBSCRIBE', 'PSUBSCRIBE', 'UNSUBSCRIBE', 'PUNSUBSCRIBE'])) {
			foreach ($this->servConnSub as $conn)  {
				$conn->command($cmd, $args, $cb);
				return;
			}

		}

		$this->getConnection(null, function ($conn) use ($cmd, $args, $cb) {
			if (!$conn->isConnected()) {
				return;
			}
			$conn->command($cmd, $args, $cb);
		});
	}
}
