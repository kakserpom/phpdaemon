<?php
namespace PHPDaemon\Clients\Redis;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\CallbackWrapper;
use PHPDaemon\Network\ClientConnection;

/**
 * @package    NetworkClients
 * @subpackage RedisClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Pool extends \PHPDaemon\Network\Client {
	public $servConnSub = [];

	protected $currentMasterAddr;

	/**
	 * @TODO
	 * @param  string  $key
	 * @param  integer $timeout
	 * @return Lock
	 */
	public function lock($key, $timeout) {
		return new Lock($key, $timeout, $this);
	}

	/**
	 * Easy wrapper for queue of eval's
	 * @param  callable  $cb
	 * @return MultiEval
	 */
	public function meval($cb = null) {
		return new MultiEval($cb, $this);
	}

	/**
	 * Wrapper for scans commands
	 * @param  string  $cmd    Command
	 * @param  array   $args   Arguments
	 * @param  cllable $cbEnd  Callback
	 * @param  integer $limit  Limit
	 * @return AutoScan
	 */
	public function autoscan($cmd, $args = [], $cbEnd = null, $limit = null) {
		return new AutoScan($this, $cmd, $args, $cbEnd, $limit);
	}

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/* [string|array] Default servers */
			'servers'        => 'tcp://127.0.0.1',

			/* [integer] Default port */
			'port'           => 6379,

			/* [integer] Maximum connections per server */
			'maxconnperserv' => 32,

			/* [integer] Maximum allowed size of packet */
			'max-allowed-packet' => new \PHPDaemon\Config\Entry\Size('1M'),

			/* [boolean] If true, race condition between UNSUBSCRIBE and PUBLISH will be journaled */
			'log-pub-sub-race-condition' => true,

			/* [integer] Select storage number */
			'select' => null,

			/* [integer] <master name> for Sentinel */
			'sentinel-master' => null,
		];
	}

	/**
	 * @TODO
	 * @param  string $chan
	 * @return integer
	 */
	public function getLocalSubscribersCount($chan) {
		foreach ($this->servConnSub as $conn)  {
			return $conn->getLocalSubscribersCount($chan);
		}
		return 0;
	}

	/**
	 * Magic __call
	 * Example:
	 * $redis->lpush('mylist', microtime(true));
	 * @param  string $name Command name
	 * @param  array  $args Arguments
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

		if ($cmd === 'SENTINEL' || !isset($this->config->sentinelmaster->value)) {
			$this->sendCommand(null, $cmd, $args, $cb);
			return;
		}
		if ($this->currentMasterAddr !== null) {
			$this->sendCommand($this->currentMasterAddr, $cmd, $args, $cb);
			return;
		}
		$this->sentinel('get-master-addr-by-name', $this->config->sentinelmaster->value, function($redis) use ($cmd, $args, $cb) {
			$this->currentMasterAddr = 'tcp://' . $redis->result[0] .':' . $redis->result[1];
			$this->sendCommand($this->currentMasterAddr, $cmd, $args, $cb);
		});
	}

	/**
	 * @TODO
	 * @param  string   $addr
	 * @param  string   $cmd
	 * @param  array    $args
	 * @param  callable $cb
	 * @callback $cb ( )
	 * @return void
	 */
	protected function sendCommand($addr, $cmd, $args, $cb) {
		$this->getConnection($addr, function ($conn) use ($cmd, $args, $cb) {
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
	 * @TODO
	 * @param  string   $cmd
	 * @param  array    $args
	 * @param  callable $cb
	 * @callback $cb ( )
	 * @return boolean
	 */
	protected function sendSubCommand($cmd, $args, $cb) {
		if (in_array($cmd, ['SUBSCRIBE', 'PSUBSCRIBE', 'UNSUBSCRIBE', 'PUNSUBSCRIBE', 'UNSUBSCRIBEREAL'])) {
			foreach ($this->servConnSub as $conn)  {
				$conn->command($cmd, $args, $cb);
				return true;
			}

		}
		return false;
	}
}
