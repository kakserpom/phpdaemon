<?php

/**
 * BoundSocket
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
abstract class BoundSocket {

	protected $enabled = false;
	protected $fd;
	protected $ev;
	protected $pid;
	protected $pool;
	protected $listenerMode = false;
	public $ctx; // @TODO: make it protected
	protected $uri;
	protected $ctxname;
	protected $reuse = true;
	protected $ssl = false;
	protected $errorneous = false;

	protected $pkfile;
	protected $certfile;
	protected $passphrase;
	protected $verifypeer = false;
	protected $allowselfsigned = true;

	protected $source;
	protected $revision;
	public function __construct($uri) {
		$this->uri = is_array($uri) ? $uri : Daemon_Config::parseSocketUri($uri);
		if (!$this->uri) {
			return;
		}
		$this->importParams();
		if ($this->ssl) {
			$this->initSSLContext();
		}
	}

	protected function importParams() {

		foreach ($this->uri['params'] as $key => $val) {
			if (isset($this->{$key}) && is_bool($this->{$key})) {
				$this->{$key} = (bool) $val;
				continue;
			}
			if (!property_exists($this, $key)) {
				Daemon::log(get_class($this).': unrecognized setting \'' . $key . '\'');
				continue;
			}
			$this->{$key} = $val;
		}
		if (!$this->ctxname) {
			return;
		}
		if (!isset(Daemon::$config->{'TransportContext:' . $this->ctxname})) {
			Daemon::log(get_class($this).': undefined transport context \'' . $this->ctxname . '\'');
			return;
		}
		$ctx = Daemon::$config->{'TransportContext:' . $this->ctxname};
		foreach ($ctx as $key => $entry) {
			$value = ($entry instanceof Daemon_ConfigEntry) ? $entry->value : $entry;
			if (isset($this->{$key}) && is_bool($this->{$key})) {
			$this->{$key} = (bool) $value;
				continue;
			}
			if (!property_exists($this, $key)) {
				Daemon::log(get_class($this).': unrecognized setting in transport context \'' . $this->ctxname . '\': \'' . $key . '\'');
				continue;
			}
			$this->{$key} = $value;	
		}

	}
	protected function initSSLContext() {
		if (!EventUtil::sslRandPoll()) {
	 		Daemon::$process->log(get_class($this->pool) . ': EventUtil::sslRandPoll failed');
	 		$this->errorneous = true;
	 		return false;
	 	}
	 	$this->ctx = new EventSslContext(EventSslContext::SSLv3_SERVER_METHOD, $a = [
 			EventSslContext::OPT_LOCAL_CERT  => $this->certfile,
 			EventSslContext::OPT_LOCAL_PK    => $this->pkfile,
 			EventSslContext::OPT_PASSPHRASE  => $this->passphrase,
 			EventSslContext::OPT_VERIFY_PEER => $this->verifypeer,
 			EventSslContext::OPT_ALLOW_SELF_SIGNED => $this->allowselfsigned,
		]);
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
					[$this, 'onListenerAcceptEv'],
					null,
					EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE,
					-1,
					$this->fd
				);
			} else {
				$this->ev->enable();
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
	
	public function onListenerAcceptEv($listener, $fd, $addrPort, $ctx)  {
		$class = $this->pool->connectionClass;
		$conn = new $class(null, $this->pool);
		$conn->setParentSocket($this);
		$conn->setPeername($addrPort[0], $addrPort[1]);
		$conn->setFd($fd);
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
			$this->ev->disable();
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
