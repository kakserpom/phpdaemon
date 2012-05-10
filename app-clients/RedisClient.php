<?php

/**
 * @package Applications
 * @subpackage RedisClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class RedisClient extends AsyncServer {

	// @todo $sessions is checked onShutdown in asyncServer ('tis NOT great)
	public $sessions = array();      // Active sessions
	private $servers = array();      // Array of servers 
	public $dtags_enabled = FALSE;   // Enables tags for distribution
	public $servConn = array();      // Active connections
	public $prefix = '';             // Prefix for all keys

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// default servers list
			'servers' => '127.0.0.1',
			// default port
			'port'    => 6379,
			// @todo add description
			'prefix'  => '',
			'maxconnectionsperserver' => 32,
		);
	}

	/**
	 * Constructor
	 * @return void
	 */
	public function init() {
		$this->prefix = $this->config->prefix->value;
		$servers = explode(',', $this->config->servers->value);

		foreach ($servers as $s) {
			$e = explode(':', $s);
			$this->addServer($e[0], isset($e[1]) ? $e[1] : NULL);
		}
	}

	/**
	 * Adds Redis server
	 * @param string Server's host
	 * @param string Server's port
	 * @param integer Weight
	 * @return void
	 */
	public function addServer($host, $port = NULL, $weight = NULL) {
		if ($port === NULL) {
			$port = $this->config->port->value;
		}

		$this->servers[$host . ':' . $port] = $weight;
	}
	
	public function __call($name, $args) {
		if (sizeof($args) && is_callable(end($args))) {
			$onResponse = array_pop($args);
		}
		else {
			$onResponse = null;
		}
		
		reset($args);
		array_unshift($args, strtoupper($name));
		$r = '*' . sizeof($args) . "\r\n";
		foreach ($args as $arg) {
			$r .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
		}
		$this->requestByServer($server = null, $r, $onResponse);
	}

	/**
	 * Returns available connection from the pool
	 * @param string Address
	 * @return object RedisSession
	 */
	public function getConnection($addr) {
		if (isset($this->servConn[$addr])) {
			while (($c = array_pop($this->servConnFree[$addr])) !== null) {
				if (isset($this->sessions[$c])) {
					return $c;
				}
			}
			if (sizeof($this->servConn[$addr]) >= $this->config->maxconnectionsperserver->value) {
				return $this->servConn[$addr][array_rand($this->servConn[$addr])];
			}
		} else {
			$this->servConn[$addr] = array();
			$this->servConnFree[$addr] = array();
		}
		

		$e = explode(':', $addr);

		$connId = $this->connectTo($e[0], $e[1]);

		$this->sessions[$connId] = new RedisSession($connId, $this);
		$this->sessions[$connId]->addr = $addr;
		$this->servConn[$addr][$connId] = $connId;
		$this->servConnFree[$addr][$connId] = $connId;

		return $connId;
	}

	/**
	 * Returns available connection from the pool by key
	 * @param string Key
	 * @return object RedisSession
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
	 * @return object RedisSession
	 */
	public function requestByServer($k, $s, $onResponse) {

		if ($k === NULL) {
			srand();
			$k = array_rand($this->servers);
		}

		$connId = $this->getConnection($k);
		$sess = $this->sessions[$connId];

		$sess->onResponse[] = $onResponse;

		$sess->write($s);
	}

	/**
	 * Sends a request to server according to the key
	 * @param string Key
	 * @param string Request
	 * @param mixed Callback called when the request complete
	 * @return object RedisSession
	 */
	public function requestByKey($k, $s, $onResponse) {
		$connId = $this->getConnectionByKey($k);

		$sess = $this->sessions[$connId];

		if ($onResponse !== NULL) {
			$sess->onResponse[] = $onResponse;
		}

		$sess->write($s);
	}
	
}

class RedisSession extends SocketSession {

	public $onResponse = array();  // stack of onResponse callbacks
	public $state = 0;             // current state of the connection
	public $result = null;      // current result (array)
	public $resultLength = 0;
	public $resultSize = 0;			// number of received array items in result
	public $value = '';
	public $valueLength = 0;        // length of incoming value
	public $valueSize = 0;         // size of received part of the value
	public $error;                 // error message
	public $key;                   // current incoming key
	public $addr;									// address of server

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	*/
	public function stdin($buf) {
		$this->buf .= $buf;

		start:
		if (($this->result !== null) && ($this->resultSize >= $this->resultLength)) {
			$f = array_shift($this->onResponse);
			if ((!$this->finished) && (!sizeof($this->onResponse))) {
				$this->appInstance->servConnFree[$this->addr][$this->connId] = $this->connId;
			}

			if ($f) {
				call_user_func($f, $this);
			}
			
			$this->resultSize = 0;
			$this->resultLength = 0;
			$this->result = NULL;
			$this->error = false;
		}
		
		if ($this->state === 0) { // outside of packet
			while (($l = $this->gets()) !== FALSE) {
				$l = rtrim($l, "\r\n");
				$char = $l[0];
				if ($char == ':') { // inline
					if ($this->result !== null) {
						++$this->resultSize;
						$this->result[] = (int) binarySubstr($char, 1);
					} else {
						$this->resultLength = 1;
						$this->resultSize = 1;
						$this->result = array((int) binarySubstr($char, 1));
					}
					goto start;
				}
				elseif (($char == '+') || ($char == '-')) { // inline
					$this->resultLength = 1;
					$this->resultSize = 1;
					$this->error = ($char == '-');
					$this->result = array(binarySubstr($char, 1));
					goto start;
				}
				elseif ($char == '*') { // defines number of elements of incoming array
					$this->resultLength = (int) substr($l, 1);
					$this->resultSize = 0;
					$this->result = array();
					goto start;
				}
				elseif ($char == '$') { // defines size of the data block
					$this->valueLength = (int) substr($l, 1);
					$this->state = 1; // data block
					break; // stop line-by-line reading
				}
			}
		}

		if ($this->state === 1) { // inside of binary string
			if ($this->valueSize < $this->valueLength) {
				$n = $this->valueLength - $this->valueSize;
				$buflen = strlen($this->buf);

				if ($buflen > $n + 2) {
					$this->value .= binarySubstr($this->buf, 0, $n);
					$this->buf = binarySubstr($this->buf, $n + 2);
				} else {
					$n = min($n, $buflen);
					$this->value .= binarySubstr($this->buf, 0, $n);
					$this->buf = '';
				}

				$this->valueSize += $n;

				if ($this->valueSize >= $this->valueLength) {
					$this->state = 0;
					++$this->resultSize;
					$this->result[] = $this->value;
					$this->value = '';
					$this->valueSize = 0;
					goto start;
				}
			}
		}
	}

	/**
	 * Called when session finishes
	 * @return void
	 */
	public function onFinish() {
		$this->finished = TRUE;

		unset($this->appInstance->servConn[$this->addr][$this->connId]);
		unset($this->appInstance->servConnFree[$this->addr][$this->connId]);
		unset($this->appInstance->sessions[$this->connId]);
	}
	
}
