<?php

/**
 * BoundSocket
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
abstract class BoundSocket {

	public $enabled = false;
	public $fd;
	public $ev;
	public $pid;
	public $pool;
	public $addr;
	public $reuse;
	public $listenerMode = false;
	public function __construct($addr, $reuse = true) {
		$this->addr = $addr;
		$this->reuse = $reuse;
	}

	public function attachTo($pool) {
		$this->pool = $pool;
		$this->pool->attachBound($this);
	}
	
	/**
	 * Route incoming request to related application
	 * @param resource Socket
	 * @return void
	 */
	public function setFd($fd) {
		$this->fd = $fd;
		$this->pid = posix_getpid();
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
		if ($this->listenerMode) {
			if ($this->ev === null) {
				$this->ev = new EventListener(
					Daemon::$process->eventBase,
					[$this, 'onListenerAcceptedEvent'],
					null,
					EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE,
					-1,
					$this->fd
				);
			} else {
				// @TODO: enable EventListener
			}
		} else {
			if ($this->ev === null) {
				$this->ev = new Event(Daemon::$process->eventBase, $this->fd, Event::READ | Event::PERSIST, array($this, 'onAcceptEv'));
				if (!$this->ev) {
					Daemon::log(get_class($this) . '::' . __METHOD__ . ': Couldn\'t set event on bound socket: ' . Debug::dump($this->fd));
					return;
				}
			} else {
				$this->onAcceptEv();
			}
			$this->ev->add();
		}
	}
	
	public function onListenerAcceptedEvent($listener, $fd, $addrPort, $ctx)  {
		//Daemon::log('new connection - '.Debug::dump([$addrPort, $fd]));
		$class = $this->pool->connectionClass;
		$conn = new $class($fd, $this->pool);
		$conn->addr = $addrPort[0].':'.$addrPort[1];
		$conn->host = $addrPort[0];
		$conn->port = $addrPort[1];
	}

	/**
	 * Disable all events of sockets
	 * @return void
	 */
	public function disable() {
		if (!$this->enabled) {
			return;
		}
		$this->enabled = false;
		if ($this->ev instanceof Event) {
			$this->ev->del();
		} elseif ($this->ev instanceof EventListener) {
			// @TODO: disable EventListener
		}
	}

	/**
	 * Close each of binded sockets.
	 * @return void
	 */
	public function close() {
		if ($this->pid != posix_getpid()) {
			return;
		}
		if ($this->ev instanceof Event) {
			$this->ev->del();
			$this->ev->free();
			$this->ev = null;
		} elseif ($this->ev instanceof EventListener) {
			$this->ev->free();
			$this->ev = null;
		}
		if ($this->fd !== null) {
			if ($this->listenerMode) {
				//$this->fd->free();
				$this->fd = null;
			} else {
				socket_close($this->fd);
			}
		}
	}


	public function finish() {
		$this->disable(); 
		$this->close();
		$this->pool->detachBound($this);
	}
	
	/**
	 * Bind given addreess
	 * @return boolean Success.
	 */
	abstract public function bindSocket();

	/**
	 * Called when new connections is waiting for accept
	 * @param resource Descriptor
	 * @param integer Events
	 * @param mixed Attached variable
	 * @return void
	 */
	public function onAcceptEv($stream = null, $events = 0, $arg = null) {
		$this->accept();
	}

	public function accept() {
		if (Daemon::$config->logevents->value) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' invoked.');
		}
		
		if (!$this->enabled) {
			return;
		}

		$fd = @socket_accept($this->fd);
		if (!$fd) {
			return;
		}
		socket_set_nonblock($fd);	
		$class = $this->pool->connectionClass;
 		return new $class($fd, $this->pool);
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
