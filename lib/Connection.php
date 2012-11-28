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
	public $failed = false;
	public $timeout = 120;
	public $locAddr;
	public $locPort;
	public $keepaliveMode = false;
	public $type;
	public function parseUrl($url) {
		if (strpos($url, '://') !== false) { // URL
			$u = parse_url($url);
			if (isset($u['host']) && (substr($u['host'], 0, 1) === '[')) {
				$u['host'] = substr($u['host'], 1, -1);
			}
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
	
	public function onInheritanceFromRequest($req) {
	}
	
	/**
	 * Called when the connection failed to be established.
	 * @return void
	 */
	public function onFailure() {
		if ($this->onConnected) {
			$this->onConnected->executeAll($this);
			$this->onConnected = null;
		}
	}
	/**
	 * Called when the connection failed
	 * @param resource Descriptor
	 * @param mixed Attached variable
	 * @return void
	 */
	public function onFailureEvent($stream, $arg = null) {
		try {
			if (!$this->connected && !$this->failed) {
				$this->failed = true;
				$this->onFailure();
			}
			$this->connected = false;
			parent::onFailureEvent($stream, $arg);
		} catch (Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
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

		$this->connectTo($this->host, $this->port);
	}

	public function connectTo($addr, $port = 0) {
		$conn = $this;
		$this->port = $port;
		if (stripos($addr, 'unix:') === 0) {
			$this->type = 'unix';
			// Unix-socket
			$this->addr = $addr;
			$e = explode(':', $addr, 2);
			$this->addr = $addr;
			$fd = socket_create(AF_UNIX, SOCK_STREAM, 0);

			if (!$fd) {
				return false;
			}
			socket_set_nonblock($fd);
			socket_set_option($fd, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->timeout, 'usec' => 0));
			socket_set_option($fd, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->timeout, 'usec' => 0));
			@socket_connect($fd, $e[1], 0);
		} 
		elseif (stripos($addr, 'raw:') === 0) {
			$this->type = 'raw';
			// Raw-socket
			$this->addr = $addr;
			$this->port = 0;
			list (, $host) = explode(':', $addr, 2);
			if (@inet_pton($host) === false) { // dirty condition check
				DNSClient::getInstance()->resolve($host, function($real) use ($conn) {
					if ($real === false) {
						Daemon::log(get_class($conn).'->connectTo: enable to resolve hostname: '.$conn->host);
						return;
					}
					$conn->connectTo('raw:'.$real);
				});
				return;
			}
			$this->hostReal = $host;
			if ($this->host === null) {
				$this->host = $this->hostReal;
			}
			$fd = socket_create(AF_INET, SOCK_RAW, 1);
			if (!$fd) {
				return false;
			}
			socket_set_option($fd, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->timeout, 'usec' => 0));
			socket_set_option($fd, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->timeout, 'usec' => 0));
			socket_set_nonblock($fd);
			@socket_connect($fd, $host, 0);
		}
		elseif (stripos($addr, 'udp:') === 0) {
			$this->type = 'udp';
			// UDP-socket
			$this->addr = $addr;
			list (, $host) = explode(':', $addr, 2);
			$pton = @inet_pton($host);
			if ($pton === false) { // dirty condition check
				DNSClient::getInstance()->resolve($host, function($real) use ($conn) {
					if ($real === false) {
						Daemon::log(get_class($conn).'->connectTo: enable to resolve hostname: '.$conn->host);
						return;
					}
					$conn->connectTo('udp:'.$real, $conn->port);
				});
				return;
			}
			$this->hostReal = $host;
			if ($this->host === null) {
				$this->host = $this->hostReal;
			}
			$this->port = $port;
			$l = strlen($pton);
			if ($l === 4) {
				$this->addr = $host . ':' . $port;
				$fd = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
			} elseif ($l === 16) {
				$this->addr = '[' . $host . ']:' . $port;
				$fd = socket_create(AF_INET6, SOCK_DGRAM, SOL_UDP);
			} else {
				return false;
			}
			if (!$fd) {
				return false;
			}
			socket_set_option($fd, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->timeout, 'usec' => 0));
			socket_set_option($fd, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->timeout, 'usec' => 0));
			socket_set_nonblock($fd);
			@socket_connect($fd, $host, $port);
			socket_getsockname($fd, $this->locAddr, $this->locPort);
		} else {
			$this->type = 'tcp';
			$host = $addr;
			$pton = @inet_pton($addr);
			if ($pton === false) { // dirty condition check
				DNSClient::getInstance()->resolve($this->host, function($real) use ($conn) {
					if ($real === false) {
						Daemon::log(get_class($conn).'->connectTo: enable to resolve hostname: '.$conn->host);
						return;
					}
					$conn->connectTo($real, $conn->port);
				});
			}
			// TCP
			$l = strlen($pton);
			if ($l === 4) {
				$this->addr = $host . ':' . $port;
				$fd = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			} elseif ($l === 16) {
				$this->addr = '[' . $host . ']:' . $port;
				$fd = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
			} else {
				return false;
			}
			if (!$fd) {
				return false;
			}
			socket_set_nonblock($fd);
			socket_set_option($fd, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->timeout, 'usec' => 0));
			socket_set_option($fd, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->timeout, 'usec' => 0));
			if ($this->keepaliveMode) {
				socket_set_option($fd, SOL_SOCKET, SO_KEEPALIVE, 1);
			}
			@socket_connect($fd, $host, $port);
			socket_getsockname($fd, $this->locAddr, $this->locPort);
		}
		$this->setFd($fd);
		return true;
	}
	public function setTimeout($timeout) {
		parent::setTimeout($timeout);
		socket_set_option($this->fd, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->timeout, 'usec' => 0));
		socket_set_option($this->fd, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->timeout, 'usec' => 0));
	}

	/**
	 * Read data from the connection's buffer
	 * @param integer Max. number of bytes to read
	 * @return string Readed data
	 */
	public function read($n) {
		if (isset($this->readEvent)) {
			$read = socket_read($this->fd, $n);

			if ($read === false) {
				$no = socket_last_error($this->fd);
				if ($no !== 11) {  // Resource temporarily unavailable
					Daemon::log(get_class($this) . '::' . __METHOD__ . ': id = ' . $this->id . '. Socket error. (' . $no . '): ' . socket_strerror($no));
					$this->onFailureEvent($this->id);
				}
			}
		} elseif (isset($this->buffer)) {
			$read = event_buffer_read($this->buffer, $n);
		} else {
			return false;
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
		socket_close($this->fd);
	}
}
