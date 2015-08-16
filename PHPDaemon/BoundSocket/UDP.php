<?php
namespace PHPDaemon\BoundSocket;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;

/**
 * UDP
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class UDP extends Generic {
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
	 * @var array [portNumber => Connection]
	 */
	protected $portsMap = [];

	/**
	 * Sets default port
	 * @param integer $port Port
	 * @return void
	 */
	public function setDefaultPort($port) {
		$this->defaultPort = $port;
	}

	/**
	 * Send UDP packet
	 * @param string $data   Data
	 * @param integer $flags Flags
	 * @param string $host   Host
	 * @param integer $port  Port
	 * @return integer
	 */
	public function sendTo($data, $flags, $host, $port) {
		return socket_sendto($this->fd, $data, strlen($data), $flags, $host, $port);
	}

	/**
	 * Unassigns addr
	 * @param string $addr Address
	 * @return void
	 */
	public function unassignAddr($addr) {
		unset($this->portsMap[$addr]);
	}

	/**
	 * Sets reuse
	 * @param integer $reuse Port
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
		$sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if (!$sock) {
			$errno = socket_last_error();
			Daemon::$process->log(get_class($this) . ': Couldn\'t create UDP-socket (' . $errno . ' - ' . socket_strerror($errno) . ').');
			return false;
		}
		if (!isset($this->port)) {
			if (isset($this->defaultPort)) {
				$this->port = $this->defaultPort;
			}
			else {
				Daemon::log(get_class($this) . ' (' . get_class($this->pool) . '): no port defined for \'' . $this->uri['uri'] . '\'');
			}
		}
		if ($this->reuse) {
			if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1)) {
				$errno = socket_last_error();
				Daemon::$process->log(get_class($this) . ': Couldn\'t set option REUSEADDR to socket (' . $errno . ' - ' . socket_strerror($errno) . ').');
				return false;
			}
			if (defined('SO_REUSEPORT') && !@socket_set_option($sock, SOL_SOCKET, SO_REUSEPORT, 1)) {
				$errno = socket_last_error();
				Daemon::$process->log(get_class($this) . ': Couldn\'t set option REUSEPORT to socket (' . $errno . ' - ' . socket_strerror($errno) . ').');
				return false;
			}
		}
		if (!@socket_bind($sock, $this->host, $this->port)) {
			$errno = socket_last_error();
			$addr  = $this->host . ':' . $this->port;
			Daemon::$process->log(get_class($this) . ': Couldn\'t bind UDP-socket \'' . $addr . '\' (' . $errno . ' - ' . socket_strerror($errno) . ').');
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
			$this->ev = new \Event(Daemon::$process->eventBase, $this->fd, \Event::READ | \Event::PERSIST, [$this, 'onReadUdp']);
			$this->onBound();
		}
		else {
			$this->onAcceptEv();
		}
		$this->ev->add();
	}

	/**
	 * Called when we got UDP packet
	 * @param resource $stream Descriptor
	 * @param integer $events  Events
	 * @param mixed $arg       Attached variable
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
			$key = '[' . $host . ']:' . $port;
			if (!isset($this->portsMap[$key])) {
				if ($this->pool->allowedClients !== null) {
					if (!self::netMatch($conn->pool->allowedClients, $host)) {
						Daemon::log('Connection is not allowed (' . $host . ')');
					}
					continue;
				}
				$class = $this->pool->connectionClass;
				$conn  = new $class(null, $this->pool);
				$conn->setDgram(true);
				$conn->onWriteEv(null);
				$conn->setPeername($host, $port);
				$conn->setParentSocket($this);
				$this->portsMap[$key] = $conn;
				$conn->timeoutRef     = setTimeout(function ($timer) use ($conn) {
					$conn->finish();
					$timer->finish();
				}, $conn->timeout * 1e6);
				$conn->onUdpPacket($buf);
			}
			else {
				$conn = $this->portsMap[$key];
				$conn->onUdpPacket($buf);
				Timer::setTimeout($conn->timeoutRef);
			}
		} while (true);
		return $host !== null;
	}
}
