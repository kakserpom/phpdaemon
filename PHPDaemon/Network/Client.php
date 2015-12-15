<?php
namespace PHPDaemon\Network;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Network\Pool;
use PHPDaemon\Network;
use PHPDaemon\Request;
use PHPDaemon\Structures\ObjectStorage;
use PHPDaemon\Structures\PriorityQueueCallbacks;

/**
 * Network client pattern
 * @package PHPDaemon\Network
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
abstract class Client extends Pool {

	/**
	 * @var array Array of servers
	 */
	protected $servers = [];

	/**
	 * @var array Active connections
	 */
	protected $servConn = [];

	/**
	 * @var array
	 */
	protected $servConnFree = [];

	/**
	 * @var string Prefix for all keys
	 */
	protected $prefix = '';

	/**
	 * @var integer
	 */
	protected $maxConnPerServ = 32;

	/**
	 * @var boolean
	 */
	protected $acquireOnGet = false;

	/**
	 * @var array
	 */
	protected $pending = [];


	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array
	 */
	protected function getConfigDefaults() {
		return [
			/* [boolean] Expose? */
			'expose'         => 1,

			/* [string|array] Default servers */
			'servers'        => '127.0.0.1',

			/* [string] Default server */
			'server'         => '127.0.0.1',

			/* [integer] Maximum connections per server */
			'maxconnperserv' => 32
		];
	}

	/**
	 * Applies config
	 * @return void
	 */
	protected function applyConfig() {
		parent::applyConfig();
		if (isset($this->config->servers)) {
			$servers       = array_filter(array_map('trim', explode(',', $this->config->servers->value)), 'strlen');
			$this->servers = [];
			foreach ($servers as $s) {
				$this->addServer($s);
			}
		}
		if (isset($this->config->maxconnperserv)) {
			$this->maxConnPerServ = $this->config->maxconnperserv->value;
		}
	}

	/**
	 * Adds server
	 * @param  string  $url    Server URL
	 * @param  integer $weight Weight
	 * @return void
	 */
	public function addServer($url, $weight = NULL) {
		$this->servers[$url] = $weight;
	}

	/**
	 * Returns available connection from the pool
	 * @param  string   $url Address
	 * @param  callback $cb  onConnected
	 * @param  integer  $pri Optional. Priority
	 * @call   ( callable $cb )
	 * @call   ( string $url = null, callable $cb = null, integer $pri = 0 )
	 * @return boolean       Success|Connection
	 */
	public function getConnection($url = null, $cb = null, $pri = 0) {
		if (!is_string($url) && $url !== null && $cb === null) { // if called getConnection(function....)
			$cb  = $url;
			$url = null;
		}
		if ($url === null) {
			if (isset($this->config->server->value)) {
				$url = $this->config->server->value;
			}
			elseif (isset($this->servers) && sizeof($this->servers)) {
				$url = array_rand($this->servers);
			}
			else {
				if ($cb) {
					call_user_func($cb, false);
				}
				return false;
			}
		}
		start:
		$conn = false;
		if (isset($this->servConn[$url])) {
			$storage = $this->servConn[$url];
			$free    = $this->servConnFree[$url];
			if ($free->count() > 0) {
				$conn = $free->getFirst();
				if (!$conn->isConnected() || $conn->isFinished()) {
					$free->detach($conn);
					goto start;
				}
				if ($this->acquireOnGet) {
					$free->detach($conn);
				}
			}
			elseif ($storage->count() >= $this->maxConnPerServ) {
				if (!isset($this->pending[$url])) {
					$this->pending[$url] = new PriorityQueueCallbacks;
				}
				$this->pending[$url]->enqueue($cb, $pri);
				return true;
			}
			if ($conn) {
				if ($cb !== null) {
					$conn->onConnected($cb);
				}
				return true;
			}
		}
		else {
			$this->servConn[$url]     = new ObjectStorage;
			$this->servConnFree[$url] = new ObjectStorage;
		}
		//Daemon::log($url . "\n" . Debug::dump($this->finished) . "\n" . Debug::backtrace(true));
		$conn = $this->connect($url, $cb);

		if (!$conn || $conn->isFinished()) {
			return false;
		}
		$this->servConn[$url]->attach($conn);
		return true;
	}

	/**
	 * Detach Connection
	 * @param  object $conn Connection
	 * @return void
	 */
	public function detach($conn) {
		parent::detach($conn);
		$this->touchPending($conn->getUrl());
	}

	/**
	 * Mark connection as free
	 * @param  ClientConnection $conn Connection
	 * @param  string           $url  URL
	 * @return void
	 */
	public function markConnFree(ClientConnection $conn, $url) {
		if (!isset($this->servConnFree[$url])) {
			return;
		}
		$this->servConnFree[$url]->attach($conn);
	}

	/**
	 * Mark connection as busy
	 * @param  ClientConnection $conn Connection
	 * @param  string           $url  URL
	 * @return void
	 */
	public function markConnBusy(ClientConnection $conn, $url) {
		if (!isset($this->servConnFree[$url])) {
			return;
		}
		$this->servConnFree[$url]->detach($conn);
	}

	/**
	 * Detaches connection from URL
	 * @param  ClientConnection $conn Connection
	 * @param  string           $url  URL
	 * @return void
	 */
	public function detachConnFromUrl(ClientConnection $conn, $url) {
		if (isset($this->servConnFree[$url])) {
			$this->servConnFree[$url]->detach($conn);
		}
		if (isset($this->servConn[$url])) {
			$this->servConn[$url]->detach($conn);
		}
	}

	/**
	 * Touch pending "requests for connection"
	 * @param  string $url URL
	 * @return void
	 */
	public function touchPending($url) {
		while (isset($this->pending[$url]) && !$this->pending[$url]->isEmpty()) {
			if (true === $this->getConnection($url, $this->pending[$url]->dequeue())) {
				return;
			}
		}
	}

	/**
	 * Returns available connection from the pool by key
	 * @param  string   $key Key
	 * @param  callable $cb  Callback
	 * @callback $cb ( )
	 * @return boolean       Success
	 */
	public function getConnectionByKey($key, $cb = null) {
		if (is_object($key)) {
			return $key->onConnected($cb);
		}
		srand(crc32($key));
		$addr = array_rand($this->servers);
		srand();
		return $this->getConnection($addr, $cb);
	}

	/**
	 * Returns available connection from the pool
	 * @param  callable $cb Callback
	 * @callback $cb ( )
	 * @return boolean      Success
	 */
	public function getConnectionRR($cb = null) {
		return $this->getConnection(null, $cb);
	}

	/**
	 * Sends a request to arbitrary server
	 * @param  string   $server     Server
	 * @param  string   $data       Data
	 * @param  callable $onResponse Called when the request complete
	 * @callback $onResponse ( )
	 * @return boolean              Success
	 */
	public function requestByServer($server, $data, $onResponse = null) {
		$this->getConnection($server, function ($conn) use ($data, $onResponse) {
			if (!$conn->isConnected()) {
				return;
			}
			$conn->onResponse($onResponse);
			$conn->write($data);
		});
		return true;
	}

	/**
	 * Sends a request to server according to the key
	 * @param  string   $key        Key
	 * @param  string   $data       Data
	 * @param  callable $onResponse Callback called when the request complete
	 * @callback $onResponse ( )
	 * @return boolean              Success
	 */
	public function requestByKey($key, $data, $onResponse = null) {
		$this->getConnectionByKey($key, function ($conn) use ($data, $onResponse) {
			if (!$conn->isConnected()) {
				return;
			}
			$conn->onResponse($onResponse);
			$conn->write($data);
		});
		return true;
	}

	/**
	 * Called when application instance is going to shutdown
	 * @param  boolean $graceful Graceful?
	 * @return boolean           Ready to shutdown?
	 */
	public function onShutdown($graceful = false) {
		return $graceful ? true : $this->finish();
	}
}
