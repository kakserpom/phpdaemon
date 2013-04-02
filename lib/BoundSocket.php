<?php

/**
 * BoundSocket
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
abstract class BoundSocket {

	/**
	 * Enabled?
	 * @var boolean
	 */
	protected $enabled = false;

	/**
	 * File descriptor
	 * @var mixed
	 */
	protected $fd;

	/**
	 * Event
	 * @var EventListener/Event
	 */
	protected $ev;

	/**
	 * PID of process which bound this socket
	 * @var int
	 */
	protected $pid;

	/**
	 * Pool
	 * @var ConnectionPool
	 */
	protected $pool;

	/**
	 * Listener mode?
	 * @var boolean
	 */
	protected $listenerMode = false;

	/**
	 * Context
	 * @var mixed
	 */
	protected $ctx;

	/**
	 * URI
	 * @var string
	 */
	protected $uri;

	/**
	 * Context name
	 * @var string
	 */
	protected $ctxname;

	/**
	 * Reuse?
	 * @var boolean
	 */
	protected $reuse = true;

	/**
	 * SSL?
	 * @var boolean
	 */
	protected $ssl = false;

	/**
	 * Errorneous?
	 * @var boolean
	 */
	protected $errorneous = false;

	/**
	 * Private key file
	 * @var string
	 */
	protected $pkfile;

	/**
	 * Certificate file
	 * @var string
	 */
	protected $certfile;

	/**
	 * Passphrase
	 * @var string
	 */
	protected $passphrase;

	/**
	 * Verify peer?
	 * @var boolean
	 */
	protected $verifypeer = false;

	/**
	 * Allow self-signed?
	 * @var boolean
	 */
	protected $allowselfsigned = true;

	/**
	 * Source
	 * @var string
	 */
	protected $source;

	/**
	 * Revision
	 * @var integer
	 */
	protected $revision;

	/**
	 * Constructor
	 * @param string URI
	 * @return object
	 */
	public function __construct($uri) {
		$this->uri = is_array($uri) ? $uri : Daemon_Config::parseCfgUri($uri);
		if (!$this->uri) {
			return;
		}
		$this->importParams();
		if ($this->ssl) {
			$this->initSSLContext();
		}
	}
	
	/**
	 * toString handler
	 * @return string
	 */
	public function __toString() {
		return $this->addr;
	}

	/**
	 * Import parameters
	 * @return void
	 */
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
		if (property_exists($this, 'port') && !isset($this->port)) {
			if (isset($this->uri['port'])) {
				$this->port = $this->uri['port'];
			} elseif ($this->defaultPort) {
				$this->port = $this->defaultPort;
			}
		}
		if (property_exists($this, 'host') && !isset($this->host)) {
			if (isset($this->uri['host'])) {
				$this->host = $this->uri['host'];
			}
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

	/**
	 * Initialize SSL context
	 * @return void
	 */
	protected function initSSLContext() {
		if (!EventUtil::sslRandPoll()) {
	 		Daemon::$process->log(get_class($this->pool) . ': EventUtil::sslRandPoll failed');
	 		$this->errorneous = true;
	 		return;
	 	}
		if (!FS::checkFileReadable($this->certfile) || !FS::checkFileReadable($this->pkfile)) {
			Daemon::log('Couldn\'t read ' . $this->certfile . ' or ' . $this->pkfile . ' file.  To generate a key' . PHP_EOL
				. 'and self-signed certificate, run' . PHP_EOL
				. '  openssl genrsa -out '.escapeshellarg($this->pkfile).' 2048' . PHP_EOL
				. '  openssl req -new -key '.escapeshellarg($this->pkfile).'  -out cert.req' . PHP_EOL
				. '  openssl x509 -req -days 365 -in cert.req -signkey '.escapeshellarg($this->pkfile).'  -out '.escapeshellarg($this->certfile));

			return;
		}

	 	$this->ctx = new EventSslContext(EventSslContext::SSLv3_SERVER_METHOD, [
 			EventSslContext::OPT_LOCAL_CERT  => $this->certfile,
 			EventSslContext::OPT_LOCAL_PK    => $this->pkfile,
 			EventSslContext::OPT_PASSPHRASE  => $this->passphrase,
 			EventSslContext::OPT_VERIFY_PEER => $this->verifypeer,
 			EventSslContext::OPT_ALLOW_SELF_SIGNED => $this->allowselfsigned,
		]);
	}

	/**
	 * Attach to ConnectionPool
	 * @param ConnectionPool
	 * @return void
	 */
	public function attachTo(ConnectionPool $pool) {
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
	 * Called when socket is bound
	 * @return boolean Success
	 */
	protected function onBound() {}
	
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
			$this->ev = new EventListener(
				Daemon::$process->eventBase,
				[$this, 'onAcceptEv'],
				null,
				EventListener::OPT_CLOSE_ON_FREE | EventListener::OPT_REUSEABLE,
				-1,
				$this->fd
			);
			$this->onBound();
		} else {
			$this->ev->enable();
		}
	}
	
	public function onAcceptEv(EventListener $listener, $fd, $addrPort, $ctx)  {
		$class = $this->pool->connectionClass;
		$conn = new $class(null, $this->pool);
		$conn->setParentSocket($this);

		if (!$this instanceof BoundUNIXSocket) {
			$conn->setPeername($addrPort[0], $addrPort[1]);
		}

		if ($this->ctx) {
			$conn->setContext($this->ctx, EventBufferEvent::SSL_ACCEPTING);
		}

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
		if (isset($this->ev)) {
			$this->ev = null;
		}
		if ($this->pid !== posix_getpid()) { // preventing closing pre-bound sockets in workers
			return;
		}
		if (is_resource($this->fd)) {
			socket_close($this->fd);
		}
	}

	/**
	 * Finishes BoundSocket
	 * @return void
	 */
	public function finish() {
		$this->disable(); 
		$this->close();
		$this->pool->detachBound($this);
	}

	/**
	 * Destructor
	 * @return void
	 */
	public function __destruct() {
		$this->close();
	}
	
	/**
	 * Bind given addreess
	 * @return boolean Success.
	 */
	abstract public function bindSocket();

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
