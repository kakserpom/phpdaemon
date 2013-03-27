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
	 * Called when socket is bound
	 * @return boolean Success
	 */
	protected function onBound() {
		if ($this->ev) {
			$this->ev->getSocketName($this->locHost, $this->locPort);
		} else {
			Daemon::log('Unable to bind TCP-socket ' . $this->host . ':' . $this->port);
		}
	}

	/**
	 * Bind the socket
	 * @return boolean Success.
	 */
	 public function bindSocket() {
	 	if ($this->errorneous) {
	 		return false;
	 	}
	 	if (!isset($this->port)) {
	 		if (isset($this->defaultPort)) {
	 			$this->port = $this->defaultPort;
	 		} else {
	 			Daemon::log(get_class($this) . ' (' . get_class($this->pool) . '): no port defined for \''.$this->uri['uri'].'\'');
	 		}
	 	}
	 	if (($this->port < 1024) && Daemon::$config->user->value !== 'root') {
	 		$this->listenerMode = false;
	 	}
		if ($this->listenerMode) {
			$this->setFd($this->host . ':' . $this->port);
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
		if (!@socket_bind($sock, $this->host, $this->port)) {
			$errno = socket_last_error();
			Daemon::$process->log(get_class($this->pool) . ': Couldn\'t bind TCP-socket \'' . $this->host . ':' . $this->uri['port'] . '\' (' . $errno . ' - ' . socket_strerror($errno) . ').');
			return false;
		}
		socket_getsockname($sock, $this->host, $this->port);
		socket_set_nonblock($sock);
		if (!$this->listenerMode) {
			if (!socket_listen($sock, SOMAXCONN)) {
				$errno = socket_last_error();
				Daemon::$process->log(get_class($this->pool) . ': Couldn\'t listen TCP-socket \'' . $this->host . ':' . $this->uri['port'] . '\' (' . $errno . ' - ' . socket_strerror($errno) . ')');
				return false;
			}
		}
		$this->setFd($sock);
		return true;
	}
}
