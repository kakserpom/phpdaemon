<?php

/**
 * Connection
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Connection extends IOStream {

	public $host;
	public $hostReal;
	public $port;
	public $onConnected = null;
	public $connected = false;
	public $timeout = 120;
	public function parseUrl($url) {
		if (strpos($url, '://') !== false) { // URL
			$u = parse_url($url);

			if (!isset($u['port'])) {
				$u['port'] = $this->pool->config->port->value;
			}
		} else {
			$e = explode(':', $url, 2);
			$u = array(
				'scheme' => 'tcp',
				'host' => $e[0],
				'port' => isset($e[1]) ? $e[1] : $this->pool->config->port->value,
			);
		}
		return $u;
	}

	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		if ($this->onConnected) {
			$this->connected = true;
			$this->onConnected->executeAll($this);
			$this->onConnected = null;
		}
	}

	/**
	 * Executes the given callback when/if the connection is handshaked
	 * Callback
	 * @return void
	 */
	public function onConnected($cb) {
		if ($this->connected) {
			call_user_func($cb, $this);
		} else {
			if (!$this->onConnected) {
				$this->onConnected = new StackCallbacks;
			}
			$this->onConnected->push($cb);
		}
	}
	

	public function connect($url, $cb) {
		$u = $this->parseUrl($url);
		if (isset($u['user'])) {
			$this->user = $u['user'];
		}
			
		$this->url = $url;
		$this->scheme = $u['scheme'];
		$this->host = $u['host'];
		$this->port = isset($u['port']) ? $u['port'] : 0;

		if (isset($u['pass'])) {
			$this->password = $u['pass'];
		}

		if (isset($u['path'])) {
			$this->path = ltrim($u['path'], '/');
		}

		if ($cb !== null) {
			$this->onConnected($cb);
		}

		$conn = $this;

		if (($this->port !== 0) && (@inet_pton($this->host) === false)) { // dirty condition check
			DNSClient::getInstance()->resolve($this->host, function($real) use ($conn) {
				if ($real === false) {
					Daemon::log(get_class($conn).'->connectTo: enable to resolve hostname: '.$conn->host);
					return;
				}
				$conn->hostReal = $real;
				$conn->connectTo($conn->hostReal, $conn->port);
			});
		}
		else {
			$conn->hostReal = $conn->host;
			$conn->connectTo($conn->hostReal, $conn->port);
		}

	}
	public function connectTo($host, $port = 0) {
		if (stripos($host, 'unix:') === 0) {
			// Unix-socket
			$e = explode(':', $host, 2);
			$this->addr = $host;
			if (Daemon::$useSockets) {
				$fd = socket_create(AF_UNIX, SOCK_STREAM, 0);

				if (!$fd) {
					return FALSE;
				}
				socket_set_nonblock($fd);
				socket_set_option($fd, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->timeout, 'usec' => 0));
				socket_set_option($fd, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->timeout, 'usec' => 0));
				@socket_connect($fd, $e[1], 0);
			} else {
				$fd = @stream_socket_client('unix://' . $e[1]);

				if (!$fd) {
					return FALSE;
				}
				stream_set_blocking($fd, 0);
			}
		} 
		elseif (stripos($host, 'raw:') === 0) {
			// Raw-socket
			$e = explode(':', $host, 2);
			$this->addr = $host;
			if (Daemon::$useSockets) {
				$fd = socket_create(AF_INET, SOCK_RAW, 1);
				if (!$fd) {
					return false;
				}
				socket_set_option($fd, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->timeout, 'usec' => 0));
				socket_set_option($fd, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->timeout, 'usec' => 0));
				socket_set_nonblock($fd);
				@socket_connect($fd, $e[1], 0);
			} else {
				return false;
			}
		} else {
			// TCP
			$this->addr = $host . ':' . $port;
			if (Daemon::$useSockets) {
				$fd = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
				if (!$fd) {
					return FALSE;
				}
				socket_set_nonblock($fd);
				socket_set_option($fd, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->timeout, 'usec' => 0));
				socket_set_option($fd, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->timeout, 'usec' => 0));
				@socket_connect($fd, $host, $port);
			} else {
				$fd = @stream_socket_client(($host === '') ? '' : $host . ':' . $port);
				if (!$fd) {
					return FALSE;
				}
				stream_set_blocking($fd, 0);
			}
		}
		$this->setFd($fd);
		return true;
	}
	
	/**
	 * Read data from the connection's buffer
	 * @param integer Max. number of bytes to read
	 * @return string Readed data
	 */
	public function read($n) {
		if (!isset($this->buffer)) {
			return false;
		}
		
		if (isset($this->readEvent)) {
			if (Daemon::$useSockets) {
				$read = socket_read($this->fd, $n);

				if ($read === false) {
					$no = socket_last_error($this->fd);

					if ($no !== 11) {  // Resource temporarily unavailable
						Daemon::log(get_class($this) . '::' . __METHOD__ . ': id = ' . $this->id . '. Socket error. (' . $no . '): ' . socket_strerror($no));
						$this->onFailureEvent($this->id);
					}
				}
			} else {
				$read = fread($this->fd, $n);
			}
		} else {
			$read = event_buffer_read($this->buffer, $n);
		}
		if (
			($read === '') 
			|| ($read === null) 
			|| ($read === false)
		) {
			$this->reading = false;
			return false;
		}
		return $read;
	}
	
	public function closeFd() {
		if (Daemon::$useSockets) {
			socket_close($this->fd);
		} else {
			fclose($this->fd);
		}
	}
}
