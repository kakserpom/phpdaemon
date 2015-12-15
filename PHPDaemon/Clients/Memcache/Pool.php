<?php
namespace PHPDaemon\Clients\Memcache;

/**
 * @package    Network clients
 * @subpackage MemcacheClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Pool extends \PHPDaemon\Network\Client {

	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/* [string|array] Default servers */
			'servers'        => 'tcp://127.0.0.1',

			/* [integer] Default port */
			'port'           => 11211,

			/* [integer] Maximum connections per server */
			'maxconnperserv' => 32
		];
	}

	/**
	 * Gets the key
	 * @param  string   $key        Key
	 * @param  callable $onResponse Callback called when response received
	 * @callback $onResponse ( )
	 * @return void
	 */
	public function get($key, $onResponse) {
		$this->requestByKey($key, 'get ' . $this->prefix . $key . "\r\n", $onResponse);
	}

	/**
	 * Sets the key
	 * @param  string   $key        Key
	 * @param  string   $value      Value
	 * @param  integer  $exp        Lifetime in seconds (0 - immortal)
	 * @param  callable $onResponse Callback called when the request complete
	 * @callback $onResponse ( )
	 * @return void
	 */
	public function set($key, $value, $exp = 0, $onResponse = null) {
		$this->getConnectionByKey($key, function ($conn) use ($key, $value, $exp, $onResponse) {
			if (!$conn->connected) {
				return;
			}
			if ($onResponse !== null) {
				$conn->onResponse->push($onResponse);
				$conn->checkFree();
			}
			$conn->writeln('set ' . $this->prefix . $key . ' 0 ' . $exp . ' '
						   . strlen($value) . ($onResponse === null ? ' noreply' : '') . "\r\n" . $value
			);
		});
	}

	/**
	 * Adds the key
	 * @param  string   $key        Key
	 * @param  string   $value      Value
	 * @param  integer  $exp        Lifetime in seconds (0 - immortal)
	 * @param  callable $onResponse Callback called when the request complete
	 * @callback $onResponse ( )
	 * @return void
	 */
	public function add($key, $value, $exp = 0, $onResponse = null) {
		$this->getConnectionByKey($key, function ($conn) use ($key, $value, $exp, $onResponse) {
			if (!$conn->connected) {
				return;
			}
			if ($onResponse !== null) {
				$conn->onResponse->push($onResponse);
				$conn->checkFree();
			}
			$conn->writeln('add ' . $this->prefix . $key . ' 0 ' . $exp . ' ' . strlen($value)
						   . ($onResponse === null ? ' noreply' : '') . "\r\n" . $value);
		});
	}

	/**
	 * Deletes the key
	 * @param  string   $key        Key
	 * @param  callable $onResponse Callback called when the request complete
	 * @param  integer  $time       Time to block this key
	 * @callback $onResponse ( )
	 * @return void
	 */
	public function delete($key, $onResponse = null, $time = 0) {
		$this->getConnectionByKey($key, function ($conn) use ($key, $time, $onResponse) {
			if (!$conn->connected) {
				return;
			}
			if ($onResponse !== null) {
				$conn->onResponse->push($onResponse);
				$conn->checkFree();
			}
			$conn->writeln('delete ' . $this->prefix . $key . ' ' . $time);
		});
	}

	/**
	 * Replaces the key
	 * @param  string   $key        Key
	 * @param  string   $value      Value
	 * @param  integer  $exp        Lifetime in seconds (0 - immortal)
	 * @param  callable $onResponse Callback called when the request complete
	 * @callback $onResponse ( )
	 * @return void
	 */
	public function replace($key, $value, $exp = 0, $onResponse = null) {
		$this->getConnectionByKey($key, function ($conn) use ($key, $value, $exp, $onResponse) {
			if (!$conn->connected) {
				return;
			}
			if ($onResponse !== null) {
				$conn->onResponse->push($onResponse);
				$conn->checkFree();
			}
			$conn->writeln('replace ' . $this->prefix . $key . ' 0 ' . $exp . ' ' . strlen($value)
						   . ($onResponse === null ? ' noreply' : '') . "\r\n" . $value);
		});
	}

	/**
	 * Appends a string to the key's value
	 * @param  string   $key        Key
	 * @param  string   $value      Value
	 * @param  integer  $exp        Lifetime in seconds (0 - immortal)
	 * @param  callable $onResponse Callback called when the request complete
	 * @callback $onResponse ( )
	 * @return void
	 */
	public function append($key, $value, $exp = 0, $onResponse = null) {
		$this->getConnectionByKey($key, function ($conn) use ($key, $value, $exp, $onResponse) {
			if (!$conn->connected) {
				return;
			}
			if ($onResponse !== null) {
				$conn->onResponse->push($onResponse);
				$conn->checkFree();
			}
			$conn->writeln('replace ' . $this->prefix . $key . ' 0 ' . $exp . ' ' . strlen($value)
						   . ($onResponse === null ? ' noreply' : '') . "\r\n" . $value);
		});
	}

	/**
	 * Prepends a string to the key's value
	 * @param  string   $key        Key
	 * @param  string   $value      Value
	 * @param  integer  $exp        Lifetime in seconds (0 - immortal)
	 * @param  callable $onResponse Callback called when the request complete
	 * @callback $onResponse ( )
	 * @return void
	 */
	public function prepend($key, $value, $exp = 0, $onResponse = null) {
		$this->getConnectionByKey($key, function ($conn) use ($key, $value, $exp, $onResponse) {
			if (!$conn->connected) {
				return;
			}
			if ($onResponse !== null) {
				$conn->onResponse->push($onResponse);
				$conn->setFree(false);
			}
			$conn->writeln('prepend ' . $this->prefix . $key . ' 0 ' . $exp . ' ' . strlen($value)
						   . ($onResponse === null ? ' noreply' : '') . "\r\n" . $value);
		});
	}

	/**
	 * Gets a statistics
	 * @param  callable $onResponse Callback called when the request complete
	 * @param  string   $server     Server
	 * @return void
	 */
	public function stats($onResponse, $server = NULL) {
		$this->requestByServer($server, 'stats' . "\r\n", $onResponse);
	}
}
