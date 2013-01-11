<?php

/**
 * Network client pattern
 * @extends ConnectionPool
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class NetworkClient extends ConnectionPool {
	
	public $servers = array();      // Array of servers 
	public $dtags_enabled = false;   // Enables tags for distribution
	public $servConn = array();      // Active connections
	public $servConnFree = array();
	public $prefix = '';             // Prefix for all keys
	public $maxConnPerServ = 32;
	public $acquireOnGet = false;
	public $noSAF = false;
	public $pending = array();
	
	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'expose'                => 1,
			'servers'               =>  '127.0.0.1',
			'server'               =>  '127.0.0.1',
			'maxconnperserv'		=> 32
		);
	}

	public function applyConfig() {
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

		if (!$conn || $conn->finished) {
			return false;
		}
		$this->servConn[$url]->attach($conn);
		return true;
	}

	public function detachConn($conn) {
		parent::detachConn($conn);
		$this->touchPending($conn->url);
	}

	public function touchPending($url) {
		while (isset($this->pending[$url]) && !$this->pending[$url]->isEmpty()) {
			$r = $this->getConnection($url, $this->pending[$url]->dequeue());
			if ($r === true) {
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
			if (!$conn->connected) {
				return;
			}
			if ($onResponse !== null) {
				$conn->onResponse->push($onResponse);
				$conn->setFree(false);
			} elseif ($conn->noSAF) {
				$conn->onResponse->push(null);
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
		 	if (!$conn->connected) {
				return;
			}
			if ($onResponse !== NULL) {
				$conn->onResponse->push($onResponse);
				$conn->setFree(false);
			} elseif ($conn->noSAF) {
				$conn->onResponse->push(null);
			}
			$conn->write($data);
		 });
		return true;
	}
}
