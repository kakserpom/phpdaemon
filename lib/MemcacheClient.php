<?php

/**
 * @package Network clients
 * @subpackage MemcacheClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
// @todo: Binary protocol
class MemcacheClient extends NetworkClient {
	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'servers'               =>  '127.0.0.1',
			'port'					=> 11211,
			'maxconnperserv'		=> 32
		);
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

		$this->requestByKey($key, 'get ' . $this->prefix . $key . "\r\n", $onResponse);
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

		$conn = $this->getConnectionByKey($key);
		if (!$conn) {
			return;
		}

		if ($onResponse !== NULL) {
			$conn->onResponse->push($onResponse);
			$conn->checkFree();
		}

		$flags = 0;

		$conn->write('set ' . $this->prefix . $key . ' ' . $flags . ' ' . $exp . ' ' 
			. strlen($value) . ($onResponse === NULL ? ' noreply' : '') . "\r\n"
		);
		$conn->write($value);
		$conn->write("\r\n");
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

		$conn = $this->getConnectionByKey($key);
		if (!$conn) {
			return false;
		}

		if ($onResponse !== NULL) {
			$conn->onResponse->push($onResponse);
			$conn->checkFree();
		}

		$flags = 0;

		$conn->write('add ' . $this->prefix . $key . ' ' . $flags . ' ' . $exp . ' ' . strlen($value) 
			. ($onResponse === null ? ' noreply' : '') . "\r\n");
		$conn->write($value);
		$conn->write("\r\n");
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

		$conn = $this->getConnectionByKey($key);
		if (!$conn) {
			return false;
		}

		$conn->onResponse->push($onResponse);
		$conn->checkFree();

		$conn->write('delete ' . $this->prefix . $key . ' ' . $time . "\r\n");
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
		$conn = $this->getConnectionByKey($key);

		if (!$conn) {
			return false;
		}

		if ($onResponse !== NULL) {
			$conn->onResponse->push($onResponse);
			$conn->setFree(false);
		}

		$flags = 0;

		$conn->write('replace ' . $this->prefix . $key . ' ' . $flags . ' ' . $exp . ' ' . strlen($value) 
			. ($onResponse === NULL ? ' noreply' : '') . "\r\n");
		$conn->write($value);
		$conn->write("\r\n");
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
		$conn = $this->getConnectionByKey($key);
		
		if (!$conn) {
			return false;
		}

		if ($onResponse !== NULL) {
			$conn->onResponse->push($onResponse);
			$conn->setFree(false);
		}

		$flags = 0;

		$conn->write('append ' . $this->prefix . $key . ' ' . $flags . ' ' . $exp . ' ' . strlen($value) 
			. ($onResponse === NULL ? ' noreply' : '') . "\r\n");
		$conn->write($value);
		$conn->write("\r\n");
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
		$conn = $this->getConnectionByKey($key);

		if (!$conn) {
			return false;
		}

		if ($onResponse !== NULL) {
			$conn->onResponse->push($onResponse);
			$conn->setFree(false);
		}

		$flags = 0;

		$conn->write('prepend ' . $this->prefix . $key . ' ' . $flags . ' ' . $exp . ' ' . strlen($value) 
			. ($onResponse === NULL ? ' noreply' : '') . "\r\n");
		$conn->write($value);
		$conn->write("\r\n");
	}

	/**
	 * Gets a statistics
	 * @param mixed Callback called when the request complete
	 * @param string Server
	 * @return void
	 */
	public function stats($onResponse, $server = NULL) {
		$this->requestByServer($server, 'stats' . "\r\n", $onResponse);
	}
}

class MemcacheClientConnection extends NetworkClientConnection {

	public $result;                // current result
	public $valueFlags;            // flags of incoming value
	public $valueLength;           // length of incoming value
	public $valueSize = 0;         // size of received part of the value
	public $error;                 // error message
	public $key;                   // current incoming key
	const STATE_DATA = 1;

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	*/
	public function stdin($buf) {
		$this->buf .= $buf;

		start:

		if ($this->state === self::STATE_ROOT) {
			while (($l = $this->gets()) !== FALSE) {
				$e = explode(' ', rtrim($l, "\r\n"));

				if ($e[0] == 'VALUE') {
					$this->key = $e[1];
					$this->valueFlags = $e[2];
					$this->valueLength = $e[3];
					$this->result = '';
					$this->state = self::STATE_DATA;
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

					$this->onResponse->executeOne($this);
					$this->checkFree();

					$this->valueSize = 0;
					$this->result = NULL;
				}
			}
		}

		if ($this->state === self::STATE_DATA) {
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
					$this->state = self::STATE_ROOT;
					goto start;
				}
			}
		}
	}
	
}
