<?php

/**
 * Network client pattern
 * @extends ConnectionPool
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class NetworkClient extends ConnectionPool {
	
	private $servers = array();      // Array of servers 
	public $dtags_enabled = false;   // Enables tags for distribution
	public $servConn = array();      // Active connections
	public $prefix = '';             // Prefix for all keys
	public $defaultServers = '127.0.0.1'; 
	public $defaultPort = 1;
	public $maxConnPerServ = 32;
	public $acquireOnGet = false;

	public function __construct($params = array()) {
		parent::__construct();
		if (!isset($params['servers'])) {
			$params['servers'] = $this->defaultServers;
		}
		if (isset($params['prefix'])) {
			$this->prefix = $params['prefix'];
		}
		if (isset($params['maxConnPerServ'])) {
			$this->maxConnPerServ = $params['maxConnPerServ'];
		}
	
		$servers = explode(',', $params['servers']);

		foreach ($servers as $s) {
			$e = explode(':', trim($s));
			$this->addServer($e[0], isset($e[1]) ? $e[1] : NULL);
		}
	}

	/**
	 * Adds server
	 * @param string Server's host
	 * @param string Server's port
	 * @param integer Weight
	 * @return void
	 */
	public function addServer($host, $port = NULL, $weight = NULL) {
		if ($port === NULL) {
			$port = $this->defaultPort;
		}
		$this->servers[$host . ':' . $port] = $weight;
	}
	
	/**
	 * Returns available connection from the pool
	 * @param string Address
	 * @return object Connection
	 */
	public function getConnection($addr = null) {
		if (isset($this->server)) {
			$addr = $this->server;
		}
		if (isset($this->servConn[$addr])) {
			if ($this->acquireOnGet) {
				while (($c = array_pop($this->servConnFree[$addr])) !== null) {
					if (isset($this->list[$c])) {
						return $this->list[$c];
					}
				}
			} else {
				if ($c = end($this->servConn[$addr])) {
					if (isset($this->list[$c])) {
						return $this->list[$c];
					}
				}
			}
			if (sizeof($this->servConn[$addr]) >= $this->maxConnPerServ) {
				return $this->getConnectionById($this->servConn[$addr][array_rand($this->servConn[$addr])]);
			}
		} else {
			$this->servConn[$addr] = array();
			$this->servConnFree[$addr] = array();
		}
		
		if (strpos($addr, '://') !== false) { // URL
			$u = parse_url($addr);

			if (!isset($u['port'])) {
				$u['port'] = $this->defaultPort;
			}

			$connId = $this->connectTo($u['host'], $u['port']);

			if (!$connId) {
				return false;
			}
			$conn = $this->getConnectionById($connId);
			
			if (isset($u['user'])) {
				$conn->user = $u['user'];
			}

			if (isset($u['pass'])) {
				$conn->password = $u['pass'];
			}

			if (isset($u['path'])) {
				$conn->dbname = ltrim($u['path'], '/');
			}
			
		} else { // not URL
			$e = explode(':', $addr);
			$connId = $this->connectTo($e[0], isset($e[1]) ? $e[1] : null);
			if (!$connId) {
				return false;
			}
			$conn = $this->getConnectionById($connId);
		}

		$this->servConn[$addr][$connId] = $connId;
		$this->servConnFree[$addr][$connId] = $connId;

		return $conn;
	}

	/**
	 * Returns available connection from the pool by key
	 * @param string Key
	 * @return object Connection
	 */
	public function getConnectionByKey($key) {
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

		return $this->getConnection($addr);
	}

	/**
	 * Sends a request to arbitrary server
	 * @param string Server
	 * @param string Request
	 * @param mixed Callback called when the request complete
	 * @return object Connection
	 */
	public function requestByServer($k, $s, $onResponse) {

		if ($k === NULL) {
			srand();
			$k = array_rand($this->servers);
		}

		$conn = $this->getConnection($k);
		if (!$conn) {
			return false;
		}
		if ($onResponse !== NULL) {
			$conn->onResponse[] = $onResponse;
			$conn->checkFree();
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
	public function requestByKey($k, $s, $onResponse) {
		$conn = $this->getConnectionByKey($k);
		if (!$conn) {
			return false;
		}
		if ($onResponse !== NULL) {
			$conn->onResponse[] = $onResponse;
			$conn->checkFree();
		}
		$conn->write($s);
		return true;
	}
	
	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {}
}
