<?php

/**
 * BoundTCPSocket
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class BoundTCPSocket extends BoundSocket {
	public $defaultPort = 0;
	public $reuse = true;
	public $host;
	public $port;
	public $listenerMode = false;

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
		$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if (!$sock) {
			$errno = socket_last_error();
			Daemon::$process->log(get_class($this) . ': Couldn\'t create TCP-socket (' . $errno . ' - ' . socket_strerror($errno) . ').');
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
		if (!$this->listenerMode) {
			if (!socket_listen($sock, SOMAXCONN)) {
				$errno = socket_last_error();
				Daemon::$process->log(get_class($this) . ': Couldn\'t listen TCP-socket \'' . $addr . '\' (' . $errno . ' - ' . socket_strerror($errno) . ')');
				return false;
			}
		}
		$this->setFd($sock);
		return true;
	}


	/**
	 * Called when new connections is waiting for accept
	 * @param resource Descriptor
	 * @param integer Events
	 * @param mixed Attached variable
	 * @return boolean Success.
	 */
	public function onAcceptEvent($stream = null, $events = 0, $arg = null) {
		$conn = $this->accept();
		if (!$conn) {
			return false;
		}
		$socket = $this;
		$getpeername = function($conn) use (&$getpeername, $socket) { 
			$r = @socket_getpeername($conn->fd, $host, $port);
			if ($r === false) {
   				if (109 === socket_last_error()) { // interrupt
   					if ($conn->allowedClients !== null) {
   						$conn->ready = false; // lockwait
   					}
   					$conn->onWriteOnce($getpeername);
   					return;
   				}
   			}
			$conn->addr = $host.':'.$port;
			$conn->host = $host;
			$conn->port = $port;
			$conn->parentSocket = $socket;
			if ($conn->pool->allowedClients !== null) {
				if (!BoundTCPSocket::netMatch($conn->pool->allowedClients, $host)) {
					Daemon::log('Connection is not allowed (' . $host . ')');
					$conn->ready = false;
					$conn->finish();
				}
			}
		};
		$getpeername($conn);
		return $conn;
	}
}
