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
		$this->getConnectionByKey($key, function ($conn) use ($key, $value, $exp, $onResponse) {
			if (!$conn->connected) {
				return;
			}
			if ($onResponse !== NULL) {
				$conn->onResponse->push($onResponse);
				$conn->checkFree();
			}
			$conn->writeln('set ' . $this->prefix . $key . ' 0 ' . $exp . ' ' 
				. strlen($value) . ($onResponse === NULL ? ' noreply' : '') . "\r\n" . $value
			);
		});
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
		$this->getConnectionByKey($key, function ($conn) use ($key, $value, $exp, $onResponse) {
			if (!$conn->connected) {
				return;
			}
			if ($onResponse !== NULL) {
				$conn->onResponse->push($onResponse);
				$conn->checkFree();
			}
			$conn->writeln('add ' . $this->prefix . $key . ' 0 ' . $exp . ' ' . strlen($value) 
				. ($onResponse === null ? ' noreply' : '') . "\r\n" . $value);
		});
	}

	/**
	 * Deletes the key
	 * @param string Key
	 * @param mixed Callback called when the request complete
	 * @param integer Time to block this key
	 * @return void
	 */
	public function delete($key, $onResponse = NULL, $time = 0) {
		$this->getConnectionByKey($key, function ($conn) use ($key, $time, $onResponse) {
			if (!$conn->connected) {
				return;
			}
			if ($onResponse !== NULL) {
				$conn->onResponse->push($onResponse);
				$conn->checkFree();
			}
			$conn->writeln('delete ' . $this->prefix . $key . ' ' . $time);
		});
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
		$this->getConnectionByKey($key, function ($conn) use ($key, $value, $exp, $onResponse) {
			if (!$conn->connected) {
				return;
			}
			if ($onResponse !== NULL) {
				$conn->onResponse->push($onResponse);
				$conn->checkFree();
			}
			$conn->writeln('replace ' . $this->prefix . $key . ' 0 ' . $exp . ' ' . strlen($value) 
				. ($onResponse === NULL ? ' noreply' : '') . "\r\n" . $value);
		});
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
		$this->getConnectionByKey($key, function ($conn) use ($key, $value, $exp, $onResponse) {
			if (!$conn->connected) {
				return;
			}
			if ($onResponse !== NULL) {
				$conn->onResponse->push($onResponse);
				$conn->checkFree();
			}
			$conn->writeln('replace ' . $this->prefix . $key . ' 0 ' . $exp . ' ' . strlen($value) 
				. ($onResponse === NULL ? ' noreply' : '') . "\r\n" . $value);
		});
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
		$this->getConnectionByKey($key, function ($conn) use ($key, $value, $exp, $onResponse) {
			if (!$conn->connected) {
				return;
			}
			if ($onResponse !== NULL) {
				$conn->onResponse->push($onResponse);
				$conn->setFree(false);
			}
			$conn->writeln('prepend ' . $this->prefix . $key . ' 0 ' . $exp . ' ' . strlen($value) 
				. ($onResponse === NULL ? ' noreply' : '') . "\r\n" . $value);
		});
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
