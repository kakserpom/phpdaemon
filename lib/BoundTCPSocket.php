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
		if (Daemon::$useSockets) {
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
			socket_getsockname($sock, $host, $port);
			$addr = $host . ':' . $port;
			if (!socket_listen($sock, SOMAXCONN)) {
				$errno = socket_last_error();
				Daemon::$process->log(get_class($this) . ': Couldn\'t listen TCP-socket \'' . $addr . '\' (' . $errno . ' - ' . socket_strerror($errno) . ')');

				return false;
			}
			socket_set_nonblock($sock);
		} else {
			if (!$sock = @stream_socket_server($addr, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN)) {
				Daemon::$process->log(get_class($this) . ': Couldn\'t bind address \'' . $addr . '\' (' . $errno . ' - ' . $errstr . ')');
				return false;
			}
			stream_set_blocking($sock, 0);
		}
		$this->setFd($sock);
		return true;
	}


	/**
	 * Called when new connections is waiting for accept
	 * @param resource Descriptor
	 * @param integer Events
	 * @param mixed Attached variable
	 * @return void
	 */
	public function onAcceptEvent($stream = null, $events = 0, $arg = null) {
		$conn = parent::onAcceptEvent($stream, $events, $arg);
		if (!$conn) {
			return;
		}
		if (Daemon::$useSockets) {
			$getpeername = function($conn) use (&$getpeername) { 
				$r = @socket_getpeername($conn->fd, $host, $port);
				if ($r === false) {
    				if (109 === socket_last_error()) { // interrupt
    					if ($this->allowedClients !== null) {
    						$conn->ready = false; // lockwait
    					}
    					$conn->onWriteOnce($getpeername);
    					return;
    				}
    			}
				$conn->addr = $host.':'.$port;
				$conn->ip = $host;
				$conn->port = $port;
				if ($conn->pool->allowedClients !== null) {
					if (!BoundTCPSocket::netMatch($conn->pool->allowedClients, $host)) {
						Daemon::log('Connection is not allowed (' . $host . ')');
						$conn->ready = false;
						$conn->finish();
					}
				}
			};
			$getpeername($conn);
		}
	}

	/**
	 * Checks if the CIDR-mask matches the IP
	 * @param string CIDR-mask
	 * @param string IP
	 * @return boolean Result
	 */
	public static function netMatch($CIDR, $IP) {
		/* TODO: IPV6 */
		if (is_array($CIDR)) {
			foreach ($CIDR as &$v) {
				if (self::netMatch($v, $IP)) {
					return TRUE;
				}
			}
		
			return FALSE;
		}

		$e = explode ('/', $CIDR, 2);

		if (!isset($e[1])) {
			return $e[0] === $IP;
		}

		return (ip2long ($IP) & ~((1 << (32 - $e[1])) - 1)) === ip2long($e[0]);
	}
}
