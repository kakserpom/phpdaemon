<?php

/**
 * Connection
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Connection extends IOStream {

	protected $host;
	protected $hostReal;
	protected $port;
	protected $addr;
	protected $onConnected = null;
	protected $connected = false;
	protected $failed = false;
	protected $timeout = 120;
	protected $locAddr;
	protected $locPort;
	protected $keepalive = false;
	protected $type;
	protected $parentSocket;
	protected $dgram = false;
	protected $bevConnectEnabled = true;
	protected $bevConnect = false;
	protected $url;
	protected $scheme;

	public function isConnected() {
		return $this->connected;
	}

	public function parseUrl($url) {
		$u = Daemon_Config::parseCfgUri($url);
		if (!$u) {
			return false;
		}
		if (!isset($u['port']) && isset($this->pool->config->port->value)) {
			$u['port'] = $this->pool->config->port->value;
		}
		return $u;
	}

	public function fetchPeername() {
		if (false === socket_getpeername($this->fd, $this->host, $this->port)) {
			if (109 === socket_last_error()) {
				return null;
			}
			return false;
		}
		$this->addr = '[' . $this->host . ']:' . $this->port;
		return true;
	}

	public function setPeername($host, $port) {
		$this->host = $host;
		$this->port = $port;
		$this->addr = '[' . $this->host . ']:' . $this->port;
	}

	public function setParentSocket(BoundSocket $sock) {
		$this->parentSocket = $sock;
	}
	
	public function checkPeername() {
		$r = $this->fetchPeername();
		if ($r === false) {
	   		return;
   		}
   		if ($r === null) { // interrupt
   			if ($conn->pool->allowedClients !== null) {
   				$conn->ready = false; // lockwait
   			}
   			$conn->onWriteOnce([$this, 'checkPeername']);
   		}
		if ($this->pool->allowedClients !== null) {
			if (!BoundTCPSocket::netMatch($this->pool->allowedClients, $this->host)) {
				Daemon::log('Connection is not allowed (' . $this->host . ')');
				$this->ready = false;
				$this->finish();
			}
		}
	}

	public function onUdpPacket($pct) {}

	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		$this->connected = true;
		if ($this->onConnected) {
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
	public function onFailureEv($bev = null) {
		try {
			if (!$this->connected && !$this->failed) {
				$this->failed = true;
				$this->onFailure();
			}
			$this->connected = false;;
		} catch (Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
	}

	public function __destruct() {
		if ($this->dgram && $this->parentSocket) {
			$this->parentSocket->unassignAddr($this->addr);
		}
	}

	/**
	 * Send data to the connection. Note that it just writes to buffer that flushes at every baseloop
	 * @param string Data to send.
	 * @return boolean Success.
	 */
	public function write($data) {
		if ($this->dgram) {
			return socket_sendto($this->parentSocket->fd, $data, strlen($data), $this->finished ? MSG_EOF : 0, $this->host, $this->port);
		}
		return parent::write($data); // @todo
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
	

	public function connect($url, $cb = null) {
		$u = $this->parseUrl($url);
		if (isset($u['user'])) {
			$this->user = $u['user'];
		}
			
		$this->url = $url;
		$this->scheme = strtolower($u['scheme']);
		$this->host = isset($u['host']) ? $u['host'] : null;
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

		if ($this->scheme === 'unix') {
			$this->connectUnix($u['path']);
		}
		elseif ($this->scheme === 'raw') {
			$this->connectRaw($u['host']);
		}
		elseif ($this->scheme === 'udp') {
			$this->connectUdp($this->host, $this->port);
		}
		elseif ($this->scheme === 'tcp') {
			$this->connectTcp($this->host, $this->port);
		}
	}

	public function connectUnix($path) {
		$this->type = 'unix';
		// Unix-socket
		$fd = socket_create(AF_UNIX, SOCK_STREAM, 0);
		if (!$fd) {
			return false;
		}
		socket_set_nonblock($fd);
		socket_set_option($fd, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
		socket_set_option($fd, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
		@socket_connect($fd, $path, 0);
		$this->setFd($fd);
		return true;
	}
	public function connectRaw($host) {
		$this->type = 'raw';
		// Raw-socket
		if (@inet_pton($host) === false) { // dirty check
			DNSClient::getInstance()->resolve($host, function($result) use ($host) {
				if ($result === false) {
					Daemon::log(get_class($this).'->connectRaw : enable to resolve hostname: '.$host);
					$this->onFailureEv();
					return;
				}
				// @TODO stack of addrs
				if (is_array($result)) {
					srand(Daemon::$process->getPid());
					$real = $result[rand(0, sizeof($result) - 1)];
					srand();
				} else {
					$real = $result;
				}
				$this->connectRaw($real);
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
		socket_set_option($fd, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
		socket_set_option($fd, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
		socket_set_nonblock($fd);
		@socket_connect($fd, $host, 0);	
		$this->setFd($fd);
		return true;
	}
	public function connectUdp($host, $port) {
		$this->type = 'udp';
		// UDP-socket
		$pton = @inet_pton($host);
		if ($pton === false) { // dirty check
			DNSClient::getInstance()->resolve($host, function($result) use ($host, $port) {
				if ($result === false) {
					Daemon::log(get_class($this).'->connectUdp : enable to resolve hostname: '.$host);
					$this->onStateEv($this->bev, EventBufferEvent::ERROR);
					return;
				}
				// @todo stack of addrs
				if (is_array($result)) {
					srand(Daemon::$process->pid);
					$real = $result[rand(0, sizeof($result) - 1)];
					srand();
				} else {
					$real = $result;
				}
				$this->connectUdp($real, $port);
			});
			return;
		}
		$this->hostReal = $host;
		if ($this->host === null) {
			$this->host = $this->hostReal;
		}
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
		socket_set_option($fd, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
		socket_set_option($fd, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
		socket_set_nonblock($fd);
		@socket_connect($fd, $host, $port);
		socket_getsockname($fd, $this->locAddr, $this->locPort);
		$this->setFd($fd);
		return true;
	}
	public function connectTcp($host, $port) {
		$this->type = 'tcp';
		$pton = @inet_pton($host);
		$fd = null;
		if ($pton === false) { // dirty check
			DNSClient::getInstance()->resolve($this->host, function($result) use ($host, $port) {
				if ($result === false) {
					Daemon::log(get_class($this).'->connectTcp : enable to resolve hostname: '.$host);
					$this->onStateEv($this->bev, EventBufferEvent::ERROR);
					return;
				}
				// @todo stack of addrs
				if (is_array($result)) {
					srand(Daemon::$process->pid);
					$real = $result[rand(0, sizeof($result) - 1)];
					srand();
				} else {
					$real = $result;
				}
				$this->connectTcp($real, $port);
			});
			return;
		}
		$this->hostReal = $host;
		if ($this->host === null) {
			$this->host = $this->hostReal;
		}
		// TCP
		$l = strlen($pton);
		if ($l === 4) {
			$this->addr = $host . ':' . $port;
			if (!$this->bevConnectEnabled) {
				$fd = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			}
		} elseif ($l === 16) {
			$this->addr = '[' . $host . ']:' . $port;
			if (!$this->bevConnectEnabled) {
				$fd = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
			}
		} else {
			return false;
		}
		if (!$this->bevConnectEnabled && !$fd) {
			return false;
		}
		if (!$this->bevConnectEnabled) {
			socket_set_nonblock($fd);
			socket_set_option($fd, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
			socket_set_option($fd, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
		}
		if ($this->keepalive) {
			if (!$this->bevConnect) {
				socket_set_option($fd, SOL_SOCKET, SO_KEEPALIVE, 1);
			}
		}
		if (!$this->bevConnectEnabled) {
			@socket_connect($fd, $host, $port);
			socket_getsockname($fd, $this->locAddr, $this->locPort);
		}
		else {
			$this->bevConnect = true;
		}
		$this->setFd($fd);
		return true;
	}
	public function setTimeout($timeout) {
		parent::setTimeout($timeout);
		if ($this->fd !== null) {
			socket_set_option($this->fd, SOL_SOCKET, SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
			socket_set_option($this->fd, SOL_SOCKET, SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
		}
	}
}
