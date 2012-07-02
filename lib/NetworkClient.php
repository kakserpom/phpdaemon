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
			foreach ($servers as $s) {
				if (strpos($s, '://') !== false) {
					$this->addServer($s);
				} else {
					$e = explode(':', trim($s));
					$this->addServer($e[0], isset($e[1]) ? $e[1] : NULL);
				}
			}
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
		if (($port === NULL) && isset($this->config->defaultport->value)) {
			$port = $this->config->defaultport->value;
		}
		$this->servers[$host . ':' . $port] = $weight;
	}
	
	public function parseUrl($url) {
		if (strpos($url, '://') !== false) { // URL
			$u = parse_url($url);

			if (!isset($u['port'])) {
				$u['port'] = $this->config->defaultport->value;
			}
		} else {
			$e = explode(':', $url, 2);
			$u = array(
				'scheme' => 'tcp',
				'host' => $e[0],
				'port' => isset($e[1]) ? $e[1] : $this->config->defaultport->value,
			);
		}
		return $u;
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
			$url = $this->config->server->value;
		}
		$u = $this->parseUrl($url);
		$addr = $u['host'] . ':' . $u['port'];
		$conn = false;
		if (isset($this->servConn[$addr])) {
			if ($this->acquireOnGet) {
				while (($c = array_pop($this->servConnFree[$addr])) !== null) {
					if (isset($this->list[$c])) {
						$conn = $this->list[$c];
						break;
					}
				}
			} else {
				if ($c = end($this->servConnFree[$addr])) {
					if (isset($this->list[$c])) {
						$conn = $this->list[$c];
					}
				}
			}
			if (sizeof($this->servConn[$addr]) >= $this->maxConnPerServ) {
				$conn = $this->getConnectionById($this->servConn[$addr][array_rand($this->servConn[$addr])]);
			}
			if ($conn) {
				if ($cb !== null) {
					$conn->onConnected($cb);
				}
				return $conn;
			}
		} else {
			$this->servConn[$addr] = array();
			$this->servConnFree[$addr] = array();
		}
		
		$connId = $this->connectTo($u['host'], $u['port']);

		if (!$connId) {
			return false;
		}
	
		$conn = $this->getConnectionById($connId);
			
		if (isset($u['user'])) {
			$conn->user = $u['user'];
		}
			
		$conn->scheme = $u['scheme'];
		$conn->host = $u['host'];
		$conn->port = $u['port'];

		if (isset($u['pass'])) {
			$conn->password = $u['pass'];
		}

		if (isset($u['path'])) {
			$conn->path = ltrim($u['path'], '/');
		}
			
		$this->servConn[$addr][$connId] = $connId;
		$this->servConnFree[$addr][$connId] = $connId;

		if ($cb !== null) {
			$conn->onConnected($cb);
		}

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
	public function requestByKey($k, $s, $onResponse) {
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
