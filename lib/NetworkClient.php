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
	 * @return object Connection
	 */
	public function getConnection($url = null, $cb = null) {
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
				return false;
			}
		}
		$conn = false;
		if (isset($this->servConn[$url])) {
			$storage = $this->servConn[$url];
			$free = $this->servConnFree[$url];
			if ($free->count() > 0) {
				$conn = $free->current();
				if ($this->acquireOnGet) {
					$free->detach($conn);			
				}
			}
			elseif ($storage->count() >= $this->maxConnPerServ) {
				$conn = $storage->current();
			}
			if ($conn) {
				if ($cb !== null) {
					$conn->onConnected($cb);
				}
				return $conn;
			}
		} else {
			$this->servConn[$url] = new SplObjectStorage;
			$this->servConnFree[$url] = new SplObjectStorage;
		}
		
		$conn = $this->connect($url, $cb);

		if (!$conn) {
			return false;
		}
	
		$this->servConn[$url]->attach($conn);
		$this->servConnFree[$url]->attach($conn);

		return $conn;
	}

	/**
	 * Returns available connection from the pool by key
	 * @param string Key
	 * @return object Connection
	 */
	public function getConnectionByKey($key, $cb = null) {
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
	 * @return object Connection
	 */
	public function requestByServer($k, $s, $onResponse = null) {

		if ($k === NULL) {
			srand();
			$k = array_rand($this->servers);
		}

		$conn = $this->getConnection($k);
		if (!$conn) {
			return false;
		}
		if ($onResponse !== NULL) {
			$conn->onResponse->push($onResponse);
			$conn->setFree(false);
		} elseif ($this->noSAF) {
			$conn->onResponse->push(null);
		}
;		$conn->write($s);
		return true;
	}

	/**
	 * Sends a request to server according to the key
	 * @param string Key
	 * @param string Request
	 * @param mixed Callback called when the request complete
	 * @return boolean Success
	 */
	public function requestByKey($k, $s, $onResponse = null) {
		$conn = $this->getConnectionByKey($k);
		if (!$conn) {
			return false;
		}
		if ($onResponse !== NULL) {
			$conn->onResponse->push($onResponse);
			$conn->setFree(false);
		} elseif ($this->noSAF) {
			$conn->onResponse->push(null);
		}
		$conn->write($s);
		return true;
	}
}
