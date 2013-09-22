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

	/**
	 * Subcriptions
	 * @var array
	 */
	public $subscribeCb = [];
	public $psubscribeCb = [];

	protected $servConnSub = [];

	/**
	 * Detaches connection from URL
	 * @param ClientConnection $conn Connection
	 * @param string $url URL
	 * @return void
	 */
	public function detachConnFromUrl(ClientConnection $conn, $url) {
		 parent::detachConnFromUrl($conn, $url);
		 if (isset($this->servConnSub[$url])) {
			$this->servConnSub[$url]->detach($conn);
			if ($this->servConnSub[$url]->count() === 0) {
				unset($this->servConnSub[$url]);
			}
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
			 * Max. allowed packet
			 * @var integer
			 */
			'maxallowedpacket'           => '16M',

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
		$data = '*' . $s . "\r\n";
		foreach ($args as $arg) {
			$data .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
		}	

		$sub = false; 

		// PUB/SUB handling
		if ($name === 'SUBSCRIBE') {
			for ($i = 1; $i < $s; ++$i) {
				$arg = $args[$i];
				$this->subscribeCb[$arg][] = CallbackWrapper::wrap($onResponse);
			}
			$sub = true;
		}
		elseif ($name === 'PSUBSCRIBE') {
			for ($i = 1; $i < $s; ++$i) {
				$arg = $args[$i];
				$this->psubscribeCb[$arg][] = CallbackWrapper::wrap($onResponse);
			}
			$sub = true;
		}
		elseif ($name === 'UNSUBSCRIBE') {
			for ($i = 1; $i < $s; ++$i) {
				$arg = $args[$i];
				if (isset($this->subscribeCb[$arg])) {
					unset($this->subscribeCb[$arg]);
				}
			}
		}
		elseif ($name === 'PUNSUBSCRIBE') {
			for ($i = 1; $i < $s; ++$i) {
				$arg = $args[$i];
				if (isset($this->psubscribeCb[$arg])) {
					unset($this->psubscribeCb[$arg]);
				}
			}
		}

		if ($sub) {
			foreach ($this->servConnSub as $subOS)  {
				foreach ($subOS as $conn) {
					$conn->onResponse(null);
					$conn->write($data);
					return;
				}
				break;
			}

		}

		$this->getConnection(null, function ($conn) use ($data, $onResponse, $sub) {
			if (!$conn->isConnected()) {
				return;
			}
			if ($sub) {
				$url = $conn->url;
				if (!isset($this->servConnSub[$url])) {
					$this->servConnSub[$url] = new ObjectStorage;
				}
				$this->servConnSub[$url]->attach($conn);
				$conn->onResponse(null/*@TODO: sub success cb?*/);
				$conn->subscribed();
			} else {
				$conn->onResponse($onResponse);
			}
			$conn->write($data);
		});
	}
}
