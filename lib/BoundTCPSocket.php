<?php

/**
 * BoundTCPSocket
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class BoundTCPSocket extends BoundSocket {
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
	 * Sets default port
	 * @param integer Port
	 * @return void
	 */
	public function setDefaultPort($port) {
		$this->defaultPort = $port;
	}

	/**
	 * toString handler
	 * @return string
	 */
	public function __toString() {
		$port = isset($this->uri['port']) ? $this->uri['port'] : $this->defaultPort;
		return $this->uri['host'] . ':' . $port;
	}

	/**
	 * Bind the socket
	 * @return boolean Success.
	 */
	 public function bindSocket() {
	 	if ($this->errorneous) {
	 		return false;
	 	}
	 	$port = isset($this->uri['port']) ? $this->uri['port'] : $this->defaultPort;
	 	if (($port < 1024) && Daemon::$config->user !== 'root') {
	 		$this->listenerMode = false;
	 	}
		if ($this->listenerMode) {
			$this->setFd($this->uri['host'] . ':' . $port);
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
		if (!@socket_bind($sock, $this->uri['host'], $this->uri['port'])) {
			$errno = socket_last_error();
			Daemon::$process->log(get_class($this->pool) . ': Couldn\'t bind TCP-socket \'' . $this->uri['host'] . ':' . $this->uri['port'] . '\' (' . $errno . ' - ' . socket_strerror($errno) . ').');
			return false;
		}
		socket_getsockname($sock, $this->host, $this->port);
		socket_set_nonblock($sock);
		if (!$this->listenerMode) {
			if (!socket_listen($sock, SOMAXCONN)) {
				$errno = socket_last_error();
				Daemon::$process->log(get_class($this->pool) . ': Couldn\'t listen TCP-socket \'' . $this->uri['host'] . ':' . $this->uri['port'] . '\' (' . $errno . ' - ' . socket_strerror($errno) . ')');
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
