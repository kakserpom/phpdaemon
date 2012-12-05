<?php

/**
 * BoundUDPSocket
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class BoundUDPSocket extends BoundSocket {
	public $defaultPort = 0;
	public $reuse = true;
	public $host;
	public $port;
	public $portsMap = array();

	public function setDefaultPort($n) {
		$this->defaultPort = (int) $n;
	}
	public function setReuse($reuse = true) {
		$this->reuse = $reuse;
	}
	/**
	 * Bind given addreess
	 * @return boolean Success.
	 */
	 public function bind() {
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
	 * Called when we got UDP packet
	 * @param resource Descriptor
	 * @param integer Events
	 * @param mixed Attached variable
	 * @return boolean Success.
	 */
	public function onAcceptEvent($stream = null, $events = 0, $arg = null) {
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
			if ($l) {
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
 					$conn->onWriteEvent();
 					$conn->host = $host;
 					$conn->port = $port;
 					$conn->addr = $key;
 					$conn->parentSocket = $this;
 					$this->portsMap[$key] = $conn;
 					$conn->timeoutRef = setTimeout(function($timer) use ($conn) {
 						$conn->finish();
 						$timer->finish();
 					}, $conn->timeout * 1e6);
 					 $conn->stdin($buf);
				} else {
					$conn = $this->portsMap[$key];
					$conn->stdin($buf);
					Timer::setTimeout($conn->timeoutRef);
				}
			}
		} while ($l);

		return $host !== null;
	}
}
