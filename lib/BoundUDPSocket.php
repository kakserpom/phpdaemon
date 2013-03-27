<?php

/**
 * BoundUDPSocket
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class BoundUDPSocket extends BoundSocket {
		/**
	 * Hostname
	 * @var string
	 */
	protected $host;

	/**
	 * Port
	 * @var integer
	 */
	protected $port;

	/**
	 * Listener mode?
	 * @var boolean
	 */
	protected $listenerMode = true;

	/**
	 * Default port
	 * @var integer
	 */
	protected $defaultPort;

	/**
	 * Reuse?
	 * @var boolean
	 */
	protected $reuse = true;
	
	/**
	 * Ports map
	 * @var hash [portNumber => Connection]
	 */
	protected $portsMap = [];

	/**
	 * Sets default port
	 * @param integer Port
	 * @return void
	 */
	public function setDefaultPort($port) {
		$this->defaultPort = $port;
	}

	/**
	 * Send UDP packet
	 * @param string Data
	 * @param integer Flags
	 * @param string Host
	 * @param integer Port
	 * @return mixed
	 */
	public function sendTo($data, $flags, $host, $port) {
		return socket_sendto($this->fd, $data, strlen($data), $this->finished ? MSG_EOF : 0, $host, $port);
	}

	/**
	 * Unassigns addr
	 * @param string Address
	 * @return void
	 */
	public function unassignAddr($addr) {
		unset($this->portsMap[$addr]);
	}

	/**
	 * Sets reuse
	 * @param integer Port
	 * @return void
	 */
	public function setReuse($reuse = true) {
		$this->reuse = $reuse;
	}
	/**
	 * Bind given addreess
	 * @return boolean Success.
	 */
	 public function bindSocket() {
		$hp = explode(':', $this->addr, 2);
		if (!isset($hp[1])) {
			$hp[1] = $this->defaultPort;
		}
		$host = $hp[0];
		$port = (int) $hp[1];
		$addr = $host . ':' . $port;
		$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if (!$sock) {
			$errno = socket_last_error();
			Daemon::$process->log(get_class($this) . ': Couldn\'t create UDP-socket (' . $errno . ' - ' . socket_strerror($errno) . ').');
			return false;
		}
		if ($this->reuse) {
			if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1)) {
				$errno = socket_last_error();
				Daemon::$process->log(get_class($this) . ': Couldn\'t set option REUSEADDR to socket (' . $errno . ' - ' . socket_strerror($errno) . ').');
				return false;
			}
			if (Daemon::$reusePort && !socket_set_option($sock, SOL_SOCKET, SO_REUSEPORT, 1)) {
				$errno = socket_last_error();
				Daemon::$process->log(get_class($this) . ': Couldn\'t set option REUSEPORT to socket (' . $errno . ' - ' . socket_strerror($errno) . ').');
				return false;
			}
		}
		if (!@socket_bind($sock, $hp[0], $hp[1])) {
			$errno = socket_last_error();
			Daemon::$process->log(get_class($this) . ': Couldn\'t bind TCP-socket \'' . $addr . '\' (' . $errno . ' - ' . socket_strerror($errno) . ').');
			return false;
		}
		socket_getsockname($sock, $this->host, $this->port);
		$addr = $this->host . ':' . $this->port;
		socket_set_nonblock($sock);
		$this->setFd($sock);
		return true;
	}

	/**
	 * Called when socket is bound
	 * @return boolean Success
	 */
	protected function onBound() {
		if (!$this->ev) {
			Daemon::log(get_class($this) . '::' . __METHOD__ . ': Couldn\'t set event on bound socket: ' . Debug::dump($this->fd));
			return false;
		}
		return true;
	}

	/**
	 * Enable socket events
	 * @return void
	*/
	public function enable() {
		if ($this->enabled) {
			return;
		}
		if (!$this->fd) {
			return;
		}
		$this->enabled = true;

		if ($this->ev === null) {
			$this->ev = new Event(Daemon::$process->eventBase, $this->fd, Event::READ | Event::PERSIST, [$this, 'onReadUdp']);
			$this->onBound();
		} else {
			$this->onAcceptEv();
		}
		$this->ev->add();
	}

	/**
	 * Called when we got UDP packet
	 * @param resource Descriptor
	 * @param integer Events
	 * @param mixed Attached variable
	 * @return boolean Success.
	 */
	public function onReadUdp($stream = null, $events = 0, $arg = null) {
		if (Daemon::$config->logevents->value) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' invoked.');
		}
		
		if (Daemon::$process->reload) {
			return false;
		}

		if ($this->pool->maxConcurrency) {
			if ($this->pool->count() >= $this->pool->maxConcurrency) {
				$this->overload = true;
				return false;
			}
		}

		$host = null;
		do {
			$l = @socket_recvfrom($this->fd, $buf, 10240, MSG_DONTWAIT, $host, $port);
			if (!$l) {
				break;
			}
			$key = '['.$host . ']:' . $port;
			if (!isset($this->portsMap[$key])) {
				if ($this->pool->allowedClients !== null) {
					if (!self::netMatch($conn->pool->allowedClients, $host)) {
						Daemon::log('Connection is not allowed (' . $host . ')');
					}
					continue;
				}
				$class = $this->pool->connectionClass;
				$conn = new $class(null, $this->pool);
				$conn->dgram = true;
				$conn->onWriteEv();
				$conn->host = $host;
				$conn->port = $port;
				$conn->addr = $key;
 				$conn->parentSocket = $this;
 				$this->portsMap[$key] = $conn;
 				$conn->timeoutRef = setTimeout(function($timer) use ($conn) {
 					$conn->finish();
 					$timer->finish();
 				}, $conn->timeout * 1e6);
 				 $conn->onUdpPacket($buf);
			} else {
				$conn = $this->portsMap[$key];
				$conn->onUdpPacket($buf);
				Timer::setTimeout($conn->timeoutRef);
			}
		} while (true);
		return $host !== null;
	}
}
