<?php
namespace PHPDaemon\Clients\Redis;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\CallbackWrapper;
use PHPDaemon\Network\ClientConnection;

/**
 * @package    NetworkClients
 * @subpackage RedisClient
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Pool extends \PHPDaemon\Network\Client {
	public $servConnSub = [];

	protected $currentMasterAddr;


	public function lock($key, $timeout) {
		return new Lock($key, $timeout, $this);
	}

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


			/**
			 * If true, race condition between UNSUBSCRIBE and PUBLISH will be journaled
			 * @var boolean
			 */
			'log-pub-sub-race-condition' => true,

			/**
			 * Select storage number
			 * @var integer
			 */
			'select' => null,

			/**
			 * <master name> for Sentinel
			 * @var integer
			 */
			'sentinel-master' => null,
		];
	}

	public function getLocalSubscribersCount($chan) {
		foreach ($this->servConnSub as $conn)  {
			return $conn->getLocalSubscribersCount($chan);
		}
		return 0;
	}

	/**
	 * Magic __call.
	 * @method $cmd
	 * @param string $cmd
	 * @param array $args
	 * @usage $ .. Command-dependent set of arguments ..
	 * @usage $ [callback Callback. Optional.
	 * @example  $redis->lpush('mylist', microtime(true));
	 * @return void
	 */
	public function __call($cmd, $args) {
		$cb = null;
		for ($i = sizeof($args) - 1; $i >= 0; --$i) {
			$a = $args[$i];
			if ((is_array($a) || is_object($a)) && is_callable($a)) {
				$cb = CallbackWrapper::wrap($a);
				$args = array_slice($args, 0, $i);
				break;
			}
			elseif ($a !== null) {
				break;
			}
		}
		reset($args);
		$cmd = strtoupper($cmd);

		if ($this->sendSubCommand($cmd, $args, $cb)) {
			return;
		}

		if ($cmd === 'SENTINEL' || $this->config->sentinelmaster->value === null) {
			$this->sendCommand(null, $cmd, $args, $cb);
			return;
		}
		if ($this->currentMasterAddr !== null) {
			$this->sendCommand($this->currentMasterAddr, $cmd, $args, $cb);
			return;
		}
		$this->sentinel('get-master-addr-by-name', $this->config->sentinelmaster->value, function($redis) use ($cmd, $args, $cb) {
			$this->getConnection($this->currentMasterAddr = 'tcp://' . $redis->result[0] .':' . $redis->result[1], function ($conn) use ($cmd, $args, $cb) {
				/**
				 * @var $conn Connection
				 */

				if (!$conn->isConnected()) {
					call_user_func($cb, false);
					$this->currentMasterAddr = null;
					return;
				}

				if ($this->sendSubCommand($cmd, $args, $cb)) {
					return;
				}

				$conn->command($cmd, $args, $cb);
			});
		});
	}

	protected function sendCommand($addr, $cmd, $args, $cb) {
		$this->getConnection(null, function ($conn) use ($cmd, $args, $cb) {
			/**
			 * @var $conn Connection
			 */

			if (!$conn->isConnected()) {
				call_user_func($cb, false);
				return;
			}

			if ($this->sendSubCommand($cmd, $args, $cb)) {
				return;
			}

			$conn->command($cmd, $args, $cb);
		});
	}

	/**
	 * @param string $cmd
	 */
	protected function sendSubCommand($cmd, $args, $cb) {
		/**
		 * @var $conn Connection
		 */
		if (in_array($cmd, ['SUBSCRIBE', 'PSUBSCRIBE', 'UNSUBSCRIBE', 'PUNSUBSCRIBE', 'UNSUBSCRIBEREAL'])) {
			foreach ($this->servConnSub as $conn)  {
				$conn->command($cmd, $args, $cb);
				return true;
			}

		}
		return false;
	}
}
