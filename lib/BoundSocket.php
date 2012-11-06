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
	public $overload = false;
	public $pool;
	public $addr;
	public $reuse;
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
		$this->ev = event_new();
		if (!event_set($this->ev, $fd, EV_READ | EV_PERSIST, array($this, 'onAcceptEvent'))) {
			Daemon::log(get_class($this) . '::' . __METHOD__ . ': Couldn\'t set event on bound socket: ' . Debug::dump($fd));
			return;
		}
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
		$this->enabled = true;
		if ($this->ev) {
			event_base_set($this->ev, Daemon::$process->eventBase);
			event_add($this->ev);
		}
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
		if (!is_resource($this->ev)) {
			return;
		}
		event_del($this->ev); // bogus notice
		event_free($this->ev);
	}

	/**
	 * Close each of binded sockets.
	 * @return void
	 */
	public function close() {
		if ($this->pid != posix_getpid()) {
			return;
		}
		if (Daemon::$useSockets) {
			socket_close($this->fd);
		} else {
			fclose($this->fd);
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
	abstract public function bind();

	/**
	 * Called when new connections is waiting for accept
	 * @param resource Descriptor
	 * @param integer Events
	 * @param mixed Attached variable
	 * @return void
	 */
	public function onAcceptEvent($stream = null, $events = 0, $arg = null) {
		if (Daemon::$config->logevents->value) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . '(' . $sockId . ') invoked.');
		}
		
		if (Daemon::$process->reload) {
			return;
		}
		if ($this->pool->maxConcurrency) {
			if ($this->pool->count() >= $this->pool->maxConcurrency) {
				$this->overload = true;
				return;
			}
		}
		
		if (Daemon::$useSockets) {
			$fd = @socket_accept($stream);

			if (!$fd) {
				return;
			}
			
			socket_set_nonblock($fd);
		} else {
			$fd = @stream_socket_accept($stream, 0, $addr);

			if (!$fd) {
				return;
			}
			
			stream_set_blocking($fd, 0);
		}
		
		$class = $this->pool->connectionClass;
 		return new $class($fd, $this->pool);
	}
}
