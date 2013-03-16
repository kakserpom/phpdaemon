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
	public $reuse = false;
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
	 * Bind the socket
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
		if ($this->listenerMode) {
			$this->setFd($addr);
			return true;
		}
		$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
		if (!$sock) {
			$errno = socket_last_error();
			Daemon::$process->log(get_class($this->pool) . ': Couldn\'t create TCP-socket (' . $errno . ' - ' . socket_strerror($errno) . ').');
			return false;
		}
		if ($this->reuse) {
			if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1)) {
				$errno = socket_last_error();
				Daemon::$process->log(get_class($this->pool) . ': Couldn\'t set option REUSEADDR to socket (' . $errno . ' - ' . socket_strerror($errno) . ').');
				return false;
			}
			if (Daemon::$reusePort && !socket_set_option($sock, SOL_SOCKET, SO_REUSEPORT, 1)) {
				$errno = socket_last_error();
				Daemon::$process->log(get_class($this->pool) . ': Couldn\'t set option REUSEPORT to socket (' . $errno . ' - ' . socket_strerror($errno) . ').');
				return false;
			}
		}
		if (!@socket_bind($sock, $hp[0], $hp[1])) {
			$errno = socket_last_error();
			Daemon::$process->log(get_class($this->pool) . ': Couldn\'t bind TCP-socket \'' . $addr . '\' (' . $errno . ' - ' . socket_strerror($errno) . ').');
			return false;
		}
		socket_getsockname($sock, $this->host, $this->port);
		socket_set_nonblock($sock);
		if (!$this->listenerMode) {
			if (!socket_listen($sock, SOMAXCONN)) {
				$errno = socket_last_error();
				$addr = $this->host . ':' . $this->port;
				Daemon::$process->log(get_class($this->pool) . ': Couldn\'t listen TCP-socket \'' . $addr . '\' (' . $errno . ' - ' . socket_strerror($errno) . ')');
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
	public function onAcceptEv($stream = null, $events = 0, $arg = null) {
		$conn = $this->accept();
		if (!$conn) {
			return false;
		}
		$conn->setParentSocket($this);
		$conn->checkPeername();
		return $conn;
	}
}
