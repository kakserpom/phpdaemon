<?php

/**
 * @package Applications
 * @subpackage MemcacheClient
 */

class MemcacheClient extends AsyncServer {

	// FIXME $sessions is checked onShutdown in asyncServer ('tis NOT great)
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
			'port'    => 11211,
			// FIXME: add description
			'prefix'  => ''
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
	 * Adds memcached server
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

	/**
	 * Gets the key
	 * @param string Key
	 * @param mixed Callback called when response received
	 * @return void
	 */
	public function get($key, $onResponse) {
		if (
			!is_string($key) 
			|| !strlen($key)
		) {
			return;
		}

		$this->requestByKey($key, 'get ' . $this->prefix . $key, $onResponse);
	}

	/**
	 * Sets the key
	 * @param string Key
	 * @param string Value
	 * @param integer Lifetime in seconds (0 - immortal)
	 * @param mixed Callback called when the request complete
	 * @return void
	 */
	public function set($key, $value, $exp = 0, $onResponse = NULL) {
		if (
			!is_string($key) 
			|| !strlen($key)
		) {
			return;
		}

		$connId = $this->getConnectionByKey($key);
		$sess = $this->sessions[$connId];

		if ($onResponse !== NULL) {
			$sess->onResponse[] = $onResponse;
		}

		$flags = 0;

		$sess->write('set ' . $this->prefix . $key . ' ' . $flags . ' ' . $exp . ' ' 
			. strlen($value) . ($onResponse === NULL ? ' noreply' : '') . "\r\n"
		);
		$sess->write($value);
		$sess->write("\r\n");
	}

	/**
	 * Adds the key
	 * @param string Key
	 * @param string Value
	 * @param integer Lifetime in seconds (0 - immortal)
	 * @param mixed Callback called when the request complete
	 * @return void
	 */
	public function add($key, $value, $exp = 0, $onResponse = NULL) {
		if (
			!is_string($key) 
			|| !strlen($key)
		) {
			return;
		}

		$connId = $this->getConnectionByKey($key);

		$sess = $this->sessions[$connId];

		if ($onResponse !== NULL) {
			$sess->onResponse[] = $onResponse;
		}

		$flags = 0;

		$sess->write('add ' . $this->prefix . $key . ' ' . $flags . ' ' . $exp . ' ' . strlen($value) 
			. ($onResponse === NULL ? ' noreply' : '') . "\r\n");
		$sess->write($value);
		$sess->write("\r\n");
	}

	/**
	 * Deletes the key
	 * @param string Key
	 * @param mixed Callback called when the request complete
	 * @param integer Time to block this key
	 * @return void
	 */
	public function delete($key, $onResponse = NULL, $time = 0) {
		if (
			!is_string($key) 
			|| !strlen($key)
		) {
			return;
		}

		$connId = $this->getConnectionByKey($key);

		$sess = $this->sessions[$connId];
		$sess->onResponse[] = $onResponse;

		$sess->write($cmd = 'delete ' . $this->prefix . $key . ' ' . $time . "\r\n");
	}

	/**
	 * Replaces the key
	 * @param string Key
	 * @param string Value
	 * @param integer Lifetime in seconds (0 - immortal)
	 * @param mixed Callback called when the request complete
	 * @return void
	 */
	public function replace($key, $value, $exp = 0, $onResponse = NULL) {
		$connId = $this->getConnectionByKey($key);

		$sess = $this->sessions[$connId];

		if ($onResponse !== NULL) {
			$sess->onResponse[] = $onResponse;
		}

		$flags = 0;

		$sess->write('replace ' . $this->prefix . $key . ' ' . $flags . ' ' . $exp . ' ' . strlen($value) 
			. ($onResponse === NULL ? ' noreply' : '') . "\r\n");
		$sess->write($value);
		$sess->write("\r\n");
	}

	/**
	 * Appends a string to the key's value
	 * @param string Key
	 * @param string Value to append
	 * @param integer Lifetime in seconds (0 - immortal)
	 * @param mixed Callback called when the request complete
	 * @return void
	 */
	public function append($key, $value, $exp = 0, $onResponse = NULL) {
		$connId = $this->getConnectionByKey($key);
		$sess = $this->sessions[$connId];

		if ($onResponse !== NULL) {
			$sess->onResponse[] = $onResponse;
		}

		$flags = 0;

		$sess->write('append ' . $this->prefix . $key . ' ' . $flags . ' ' . $exp . ' ' . strlen($value) 
			. ($onResponse === NULL ? ' noreply' : '') . "\r\n");
		$sess->write($value);
		$sess->write("\r\n");
	}

	/**
	 * Prepends a string to the key's value
	 * @param string Key
	 * @param string Value to prepend
	 * @param integer Lifetime in seconds (0 - immortal)
	 * @param mixed Callback called when the request complete
	 * @return void
	 */
	public function prepend($key, $value, $exp = 0, $onResponse = NULL) {
		$connId = $this->getConnectionByKey($key);
		$sess = $this->sessions[$connId];

		if ($onResponse !== NULL) {
			$sess->onResponse[] = $onResponse;
		}

		$flags = 0;

		$sess->write('prepend ' . $this->prefix . $key . ' ' . $flags . ' ' . $exp . ' ' . strlen($value) 
			. ($onResponse === NULL ? ' noreply' : '') . "\r\n");
		$sess->write($value);
		$sess->write("\r\n");
	}

	/**
	 * Gets a statistics
	 * @param mixed Callback called when the request complete
	 * @param string Server
	 * @return void
	 */
	public function stats($onResponse, $server = NULL) {
		$this->requestByServer($server, 'stats', $onResponse);
	}

	/**
	 * Returns available connection from the pool
	 * @param string Address
	 * @return object MemcacheSession
	 */
	public function getConnection($addr) {
		if (isset($this->servConn[$addr])) {
			foreach ($this->servConn[$addr] as $k => &$c) {
				if (!isset($this->sessions[$c])) {
					unset($this->servConn[$addr][$k]);
					continue;
				}

				if (
					(!$this->sessions[$c]->finished) 
					&& (!sizeof($this->sessions[$c]->onResponse))
				) {
					return $c;
				}
			}
		} else {
			$this->servConn[$addr] = array();
		}

		$e = explode(':', $addr);

		$connId = $this->connectTo($e[0], $e[1]);

		$this->sessions[$connId] = new MemcacheSession($connId, $this);
		$this->sessions[$connId]->addr = $addr;
		$this->servConn[$addr][$connId] = $connId;

		return $connId;
	}

	/**
	 * Returns available connection from the pool by key
	 * @param string Key
	 * @return object MemcacheSession
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
	 * @return object MemcacheSession
	 */
	public function requestByServer($k, $s, $onResponse) {
		if ($k == '*') {
			$result = array();

			foreach ($this->servers as $k => $v) {
				$connId = $this->getConnection($k);

				$sess = $this->sessions[$connId];
				$sess->onResponse = $onResponse;

				$sess->write($s);
				$sess->write("\r\n");
			}

			return $result;
		}

		if ($k === NULL) {
			srand();
			$k = array_rand($this->servers);
		}

		$connId = $this->getConnection($k);
		$sess = $this->sessions[$connId];

		if ($onResponse !== NULL) {
			$sess->onResponse[] = $onResponse;
		}

		$sess->write($s);
		$sess->write("\r\n");
	}

	/**
	 * Sends a request to server according to the key
	 * @param string Key
	 * @param string Request
	 * @param mixed Callback called when the request complete
	 * @return object MemcacheSession
	 */
	public function requestByKey($k, $s, $onResponse) {
		$connId = $this->getConnectionByKey($k);

		$sess = $this->sessions[$connId];

		if ($onResponse !== NULL) {
			$sess->onResponse[] = $onResponse;
		}

		$sess->write($s);
		$sess->write("\r\n");
	}
	
}

class MemcacheSession extends SocketSession {

	public $addr;
	public $onResponse = array();  // stack of onResponse callbacks
	public $state = 0;             // current state of the connection
	public $result;                // current result
	public $valueFlags;            // flags of incoming value
	public $valueLength;           // length of incoming value
	public $valueSize = 0;         // size of received part of the value
	public $error;                 // error message
	public $key;                   // current incoming key
	public $finished = FALSE;      // is this session finished?

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	*/
	public function stdin($buf) {
		$this->buf .= $buf;

		start:

		if ($this->state === 0) {
			while (($l = $this->gets()) !== FALSE) {
				$e = explode(' ', rtrim($l, "\r\n"));

				if ($e[0] == 'VALUE') {
					$this->key = $e[1];
					$this->valueFlags = $e[2];
					$this->valueLength = $e[3];
					$this->result = '';
					$this->state = 1;
					break;
				}
				elseif ($e[0] == 'STAT') {
					if ($this->result === NULL) {
						$this->result = array();
					}

					$this->result[$e[1]] = $e[2];
				}
				elseif (
					($e[0] === 'STORED') 
					|| ($e[0] === 'END') 
					|| ($e[0] === 'DELETED') 
					|| ($e[0] === 'ERROR') 
					|| ($e[0] === 'CLIENT_ERROR') 
					|| ($e[0] === 'SERVER_ERROR')
				) {
					if ($e[0] !== 'END') {
						$this->result = FALSE;
						$this->error = isset($e[1]) ? $e[1] : NULL;
					}

					$f = array_shift($this->onResponse);

					if ($f) {
						call_user_func($f, $this);
					}

					$this->valueSize = 0;
					$this->result = NULL;
				}
			}
		}

		if ($this->state === 1) {
			if ($this->valueSize < $this->valueLength) {
				$n = $this->valueLength-$this->valueSize;
				$buflen = strlen($this->buf);

				if ($buflen > $n) {
					$this->result .= binarySubstr($this->buf, 0, $n);
					$this->buf = binarySubstr($this->buf, $n);
				} else {
					$this->result .= $this->buf;
					$n = $buflen;
					$this->buf = '';
				}

				$this->valueSize += $n;

				if ($this->valueSize >= $this->valueLength) {
					$this->state = 0;
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
		unset($this->appInstance->sessions[$this->connId]);
	}
	
}
