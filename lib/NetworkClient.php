<?php

/**
 * Network client pattern
 * @extends ConnectionPool
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class NetworkClient extends ConnectionPool {
	
	protected $servers = array();      // Array of servers 
	protected $dtags_enabled = false;   // Enables tags for distribution
	protected $servConn = [];      // Active connections
	protected $servConnFree = [];
	protected $prefix = '';             // Prefix for all keys
	protected $maxConnPerServ = 32;
	protected $acquireOnGet = false;
	protected $noSAF = false;
	protected $pending = [];
	
	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			/**
			 * Expose?
			 * @var boolean
			 */
			'expose'                => 1,

			/**
			 * Default servers
			 * @var string|array
			 */
			'servers'               =>  '127.0.0.1',

			/**
			 * Default server
			 * @var string
			 */
			'server'               =>  '127.0.0.1',

			/**
			 * Maximum connections per server
			 * @var integer
			 */
			'maxconnperserv'		=> 32
		];
	}

	/**
	 * Applies config
	 * @return void
	 */
	protected function applyConfig() {
		parent::applyConfig();
		if (isset($this->config->servers)) {
			$servers = array_filter(array_map('trim',explode(',', $this->config->servers->value)), 'strlen');
			$this->servers = array();
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
	 * @param string Server URL
	 * @param integer Weight
	 * @return void
	 */
	public function addServer($url, $weight = NULL) {
		$this->servers[$url] = $weight;
	}

	/**
	 * Returns available connection from the pool
	 * @param string Address
	 * @param callback onConnected
	 * @param integer Optional. Priority.
	 * @return mixed Success|Connection.
	 */
	public function getConnection($url = null, $cb = null, $pri = 0) {
		if (!is_string($url) && $url !== null && $cb === null) { // if called getConnection(function....)
			$cb = $url;
			$url = null; 
		}
		if ($url === null) {

			if (isset($this->config->server->value)) {
				$url = $this->config->server->value;
			} elseif (isset($this->servers) && sizeof($this->servers)) {
				$url = array_rand($this->servers);
			} else {
				if ($cb) {
					call_user_func($cb, false);
				}
				return true;
			}
		}
		$conn = false;
		if (isset($this->servConn[$url])) {
			$storage = $this->servConn[$url];
			$free = $this->servConnFree[$url];
			if ($free->count() > 0) {
				$conn = $free->getFirst();
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
		} else {
			$this->servConn[$url] = new ObjectStorage;
			$this->servConnFree[$url] = new ObjectStorage;
		}
		$conn = $this->connect($url, $cb);

		if (!$conn || $conn->isFinished()) {
			return false;
		}
		$this->servConn[$url]->attach($conn);
		return true;
	}

	/**
	 * Detach Connection
	 * @param Connection
	 * @param [mixed Info]
	 * @return void
	 */
	public function detach($conn) {
		parent::detach($conn);
		$this->touchPending($conn->getUrl());
	}

	/**
	 * Mark connection as free
	 * @param Connection
	 * @param URL
	 * @return void
	 */
	public function markConnFree(NetworkClientConnection $conn, $url) {
		if (!isset($this->servConnFree[$url])) {
			return;
		}
		$this->servConnFree[$url]->attach($conn);
	}


	/**
	 * Mark connection as busy
	 * @param Connection
	 * @param URL
	 * @return void
	 */
	public function markConnBusy(NetworkClientConnection $conn, $url) {
		if (!isset($this->servConnFree[$url])) {
			return;
		}
		$this->servConnFree[$url]->detach($conn);
	}

	/**
	 * Detaches connection from URL
	 * @param Connection
	 * @param URL
	 * @return void
	 */
	public function detachConnFromUrl(NetworkClientConnection $conn, $url) {
		if (!isset($this->servConnFree[$url]) || !isset($this->servConn[$url]))	 {
			return;
		}
		$this->servConnFree[$url]->detach($conn);
		$this->servConn[$url]->detach($conn);
	}


	/**
	 * Touch pending "requests for connection"
	 * @param URL
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
	 * @param string Key
	 * @return boolean Success.
	 */
	public function getConnectionByKey($key, $cb = null) {
		if (is_object($key)) {
			return $key->onConnected($cb);
		}
		if (
			($this->dtags_enabled) 
			&& (($sp = strpos($key, '[')) !== FALSE) 
			&& (($ep = strpos($key, ']')) !== FALSE) 
			&& ($ep > $sp)
		) {
			$key = substr($key, $sp + 1, $ep - $sp - 1);
		}

		srand(crc32($key));
		$addr = array_rand($this->servers);
		srand();
		return $this->getConnection($addr, $cb);
	}

	/**
	 * Sends a request to arbitrary server
	 * @param string Server
	 * @param string Request
	 * @param mixed Callback called when the request complete
	 * @return boolean Success.
	 */
	public function requestByServer($server, $data, $onResponse = null) {

		if ($server === NULL) {
			srand();
			$server = array_rand($this->servers);
		}
		$this->getConnection($server, function ($conn) use ($data, $onResponse) {
			if (!$conn->isConnected()) {
				return;
			}
			if ($onResponse !== null) {
				$conn->onResponse($onResponse);
				$conn->setFree(false);
			} elseif ($conn->noSAF) {
				$conn->onResponse(null);
			}
			$conn->write($data);
		});
		return true;
	}

	/**
	 * Sends a request to server according to the key
	 * @param string Key
	 * @param string Request
	 * @param mixed Callback called when the request complete
	 * @return boolean Success
	 */
	public function requestByKey($key, $data, $onResponse = null) {
		 $this->getConnectionByKey($key, function ($conn) use ($data, $onResponse) {
		 	if (!$conn->isConnected()) {
				return;
			}
			if ($onResponse !== NULL) {
				$conn->onResponse($onResponse);
				$conn->setFree(false);
			} elseif ($conn->noSAF) {
				$conn->onResponse(null);
			}
			$conn->write($data);
		 });
		return true;
	}
}
