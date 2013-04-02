<?php

/**
 * Connection
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
abstract class Connection extends IOStream {

	/**
	 * Path
	 * @var string
	 */
	protected $path;

	/**
	 * Hostname
	 * @var string
	 */
	protected $host;

	/**
	 * Real host
	 * @var string
	 */
	protected $hostReal;

	/**
	 * Port number
	 * @var integer
	 */
	protected $port;

	/**
	 * Address
	 * @var string
	 */
	protected $addr;

	/**
	 * Stack of callbacks called when connection is established
	 * @var object StackCallbacks
	 */
	protected $onConnected = null;

	/**
	 * Connected?
	 * @var boolean
	 */
	protected $connected = false;

	/**
	 * Failed?
	 * @var boolean
	 */
	protected $failed = false;

	/**
	 * Timeout
	 * @var integer
	 */
	protected $timeout = 120;

	/**
	 * Local address
	 * @var string
	 */
	protected $locAddr;

	/**
	 * Local port
	 * @var integer
	 */
	protected $locPort;

	/**
	 * Keepalive?
	 * @var boolean
	 */
	protected $keepalive = false;

	/**
	 * Type
	 * @var string
	 */
	protected $type;

	/**
	 * Parent socket
	 * @var BoundSocket
	 */
	protected $parentSocket;

	/**
	 * Dgram connection?
	 * @var boolean
	 */
	protected $dgram = false;

	/**
	 * Enable bevConnect?
	 * @var boolean
	 */
	protected $bevConnectEnabled = true;

	/**
	 * SSL?
	 * @var boolean
	 */
	protected $ssl = false;

	/**
	 * URL
	 * @var string
	 */
	protected $url;

	/**
	 * URI information
	 * @var hash
	 */
	protected $uri;

	/**
	 * Scheme
	 * @var string
	 */
	protected $scheme;


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
	 * Context cache
	 * @var CappedCacheStorage
	 */
	protected static $contextCache;

	/**
	 * Context cache size
	 * @var number
	 */
	protected static $contextCacheSize = 64;
	/**
	 * Connected?
	 * @return boolean
	 */
	public function isConnected() {
		return $this->connected;
	}

	/**
	 * Sets peer name.
	 * @param string Hostname
	 * @param integer Port
	 * @return void
	 */
	public function setPeername($host, $port) {
		$this->host = $host;
		$this->port = $port;
		$this->addr = '[' . $this->host . ']:' . $this->port;
		if ($this->pool->allowedClients !== null) {
			if (!BoundTCPSocket::netMatch($this->pool->allowedClients, $this->host)) {
				Daemon::log('Connection is not allowed (' . $this->host . ')');
				$this->ready = false;
				$this->finish();
			}
		}
	}

	/**
	 * Getter
	 * @param string Name
	 * @return void
	 */
	public function __get($name) {
		if (in_array($name, [
				'connected', 'hostReal', 'host', 'port', 'finished',
				'alive', 'freed', 'url'
		])) {
			return $this->{$name};
		}
		return null;
	}
	/**
	 * Get socket name
	 * @param &string Addr
	 * @param &srting Port
	 * @return void
	 */
	public function getSocketName(&$addr, &$port) {
		if (func_num_args() === 0) {
			EventUtil::getSocketName($this->bev->fd, $this->locAddr, $this->locPort);
			return;
		}
		EventUtil::getSocketName($this->bev->fd, $addr, $port);
	}

	/**
	 * Sets parent socket
	 * @param BoundSocket
	 * @return boolean Success
	 */
	public function setParentSocket(BoundSocket $sock) {
		$this->parentSocket = $sock;
	}

	/**
	 * Called when new UDP packet received
	 * @return void
	 */
	public function onUdpPacket($pct) {}

	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		$this->connected = true;
		if ($this->onConnected) {
			$this->onConnected->executeAll($this);
			$this->onConnected = null;
		}
	}
	

	/**
	 * Called if we inherit connection from request
	 * @param Request Parent Request.
	 * @return void
	 */
	public function onInheritanceFromRequest($req) {
	}
	
	/**
	 * Called when the connection failed to be established.
	 * @return void
	 */
	public function onFailure() {
		if ($this->onConnected) {
			$this->onConnected->executeAll($this);
			$this->onConnected = null;
		}
	}
	/**
	 * Called when the connection failed
	 * @param resource Descriptor
	 * @param mixed Attached variable
	 * @return void
	 */
	public function onFailureEv($bev = null) {
		try {
			if (!$this->connected && !$this->failed) {
				$this->failed = true;
				$this->onFailure();
			}
			$this->connected = false;;
		} catch (Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
	}

	/**
	 * Destructor
	 * @return void
	 */

	public function __destruct() {
		if ($this->dgram && $this->parentSocket) {
			$this->parentSocket->unassignAddr($this->addr);
		}
	}

	/**
	 * Send data to the connection. Note that it just writes to buffer that flushes at every baseloop
	 * @param string Data to send.
	 * @return boolean Success.
	 */
	public function write($data) {
		if ($this->dgram) {
			return $this->parentSocket->sendTo($data, $this->finished ? MSG_EOF : 0, $this->host, $this->port);
		}
		return parent::write($data); // @todo
	}

	/**
	 * Executes the given callback when/if the connection is handshaked
	 * Callback
	 * @return void
	 */
	public function onConnected($cb) {
		if ($this->connected) {
			call_user_func($cb, $this);
		} else {
			if (!$this->onConnected) {
				$this->onConnected = new StackCallbacks;
			}
			$this->onConnected->push($cb);
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

	/**
	 * Initialize SSL context
	 * @return object|false Context
	 */
	protected function initSSLContext() {
		if (!EventUtil::sslRandPoll()) {
	 		Daemon::$process->log(get_class($this->pool) . ': EventUtil::sslRandPoll failed');
	 		return false;
	 	}
	 	$params = [
 			EventSslContext::OPT_VERIFY_PEER => $this->verifypeer,
 			EventSslContext::OPT_ALLOW_SELF_SIGNED => $this->allowselfsigned,
		];
		if ($this->certfile !== null) {
			$params[EventSslContext::OPT_LOCAL_CERT] = $this->certfile;
		}
		if ($this->pkfile !== null) {
			$params[EventSslContext::OPT_LOCAL_PK] = $this->pkfile;
		}
		if ($this->passphrase !== null) {
			$params[EventSslContext::OPT_PASSPHRASE] = $this->passphrase;
		}
		$hash = igbinary_serialize($params);
		if (!self::$contextCache) {
			self::$contextCache = new CappedCacheStorageHits(self::$contextCacheSize);
		} elseif ($ctx = self::$contextCache->getValue($hash)) {
			return $ctx;
		}
		$ctx = new EventSslContext(EventSslContext::SSLv3_CLIENT_METHOD, $params);
		self::$contextCache->put($hash, $ctx);
		return $ctx;
	}

	/**
	 * Get URL
	 * @return string
	 */
	public function getUrl() {
		return $this->url;
	}

	/**
	 * Get host
	 * @return string
	 */
	public function getHost() {
		return $this->host;
	}

	/**
	 * Get port
	 * @return string
	 */
	public function getPort() {
		return $this->port;
	}

	/**
	 * Connects to URL
	 * @param string URL
	 * @param callable Callback
	 * @return boolean Success
	 */
	public function connect($url, $cb = null) {
		$this->uri = Daemon_Config::parseCfgUri($url);
		$u =& $this->uri;
		if (!$u) {
			return false;
		}
		$this->importParams();
		if (!isset($u['port'])) {
			if ($this->ssl) {
				if (isset($this->pool->config->sslport->value)) {
					$u['port'] = $this->pool->config->sslport->value;
 				}
			} else {
				if (isset($this->pool->config->port->value)) {
					$u['port'] = $this->pool->config->port->value;
 				}
			}
		}
		if (isset($u['user'])) {
			$this->user = $u['user'];
		}

		if ($this->ssl) {
			$this->setContext($this->initSSLContext(), EventBufferEvent::SSL_CONNECTING);
		}
			
		$this->url = $url;
		$this->scheme = strtolower($u['scheme']);
		$this->host = isset($u['host']) ? $u['host'] : null;
		$this->port = isset($u['port']) ? $u['port'] : 0;

		if (isset($u['pass'])) {
			$this->password = $u['pass'];
		}

		if (isset($u['path'])) {
			$this->path = ltrim($u['path'], '/');
		}

		if ($cb !== null) {
			$this->onConnected($cb);
		}

		if ($this->scheme === 'unix') {
			return $this->connectUnix($u['path']);
		}
		if ($this->scheme === 'raw') {
			return $this->connectRaw($u['host']);
		}
		if ($this->scheme === 'udp') {
			return $this->connectUdp($this->host, $this->port);
		}
		if ($this->scheme === 'tcp') {
			return $this->connectTcp($this->host, $this->port);
		}
		Daemon::log(get_class($this).': connect(): unrecoginized scheme \''.$this->scheme.'\' (not unix/raw/udp/tcp) in URL: '.$url);
		return false;
	}

	/* Establish UNIX socket connection
	 * @param string Path
	 * @return boolean Success
	 */
	public function connectUnix($path) {
		$this->type = 'unix';

		if (!$this->bevConnectEnabled) {
			$fd = socket_create(EventUtil::AF_UNIX, EventUtil::SOCK_STREAM, 0);
			if (!$fd) {
				return false;
			}
			socket_set_nonblock($fd);
			@socket_connect($fd, $path, 0);
			$this->setFd($fd);
			return true;
		}
		$this->bevConnect = true;
		$this->addr = 'unix:' . $path;
		$this->setFd(null);
		return true;
	}


	/* Establish raw socket connection
	 * @param string Path
	 * @return boolean Success
	 */
	public function connectRaw($host) {
		$this->type = 'raw';
		if (@inet_pton($host) === false) { // dirty check
			DNSClient::getInstance()->resolve($host, function($result) use ($host) {
				if ($result === false) {
					Daemon::log(get_class($this).'->connectRaw : enable to resolve hostname: '.$host);
					$this->onFailureEv();
					return;
				}
				// @TODO stack of addrs
				if (is_array($result)) {
					srand(Daemon::$process->getPid());
					$real = $result[rand(0, sizeof($result) - 1)];
					srand();
				} else {
					$real = $result;
				}
				$this->connectRaw($real);
			});
			return true;
		}
		$this->hostReal = $host;
		if ($this->host === null) {
			$this->host = $this->hostReal;
		}
		$this->addr = $this->hostReal . ':raw';
		$fd = socket_create(EventUtil::AF_INET, EventUtil::SOCK_RAW, 1);
		if (!$fd) {
			return false;
		}
		socket_set_nonblock($fd);
		@socket_connect($fd, $host, 0);	
		$this->setFd($fd);
		if (!$this->bev) {
			return false;
		}
		return true;
	}

	/* Establish UDP connection
	 * @param string Hostname
	 * @param integer Port
	 * @return boolean Success
	 */
	public function connectUdp($host, $port) {
		$this->type = 'udp';
		$pton = @inet_pton($host);
		if ($pton === false) { // dirty check
			DNSClient::getInstance()->resolve($host, function($result) use ($host, $port) {
				if ($result === false) {
					Daemon::log(get_class($this).'->connectUdp : enable to resolve hostname: '.$host);
					$this->onStateEv($this->bev, EventBufferEvent::ERROR);
					return;
				}
				// @todo stack of addrs
				if (is_array($result)) {
					srand(Daemon::$process->pid);
					$real = $result[rand(0, sizeof($result) - 1)];
					srand();
				} else {
					$real = $result;
				}
				$this->connectUdp($real, $port);
			});
			return true;
		}
		$this->hostReal = $host;
		if ($this->host === null) {
			$this->host = $this->hostReal;
		}
		$l = strlen($pton);
		if ($l === 4) {
			$this->addr = $host . ':' . $port;
			$fd = socket_create(EventUtil::AF_INET, EventUtil::SOCK_DGRAM, EventUtil::SOL_UDP);
		} elseif ($l === 16) {
			$this->addr = '[' . $host . ']:' . $port;
			$fd = socket_create(EventUtil::AF_INET6, EventUtil::SOCK_DGRAM, EventUtil::SOL_UDP);
		} else {
			return false;
		}
		if (!$fd) {
			return false;
		}
		socket_set_nonblock($fd);
		@socket_connect($fd, $host, $port);
		socket_getsockname($fd, $this->locAddr, $this->locPort);
		$this->setFd($fd);
		if (!$this->bev) {
			return false;
		}
		return true;
	}

	/* Establish TCP connection
	 * @param string Hostname
	 * @param integer Port
	 * @return boolean Success
	 */
	public function connectTcp($host, $port) {
		$this->type = 'tcp';
		$pton = @inet_pton($host);
		$fd = null;
		if ($pton === false) { // dirty check
			DNSClient::getInstance()->resolve($this->host, function($result) use ($host, $port) {
				if ($result === false) {
					Daemon::log(get_class($this).'->connectTcp : enable to resolve hostname: '.$host);
					$this->onStateEv($this->bev, EventBufferEvent::ERROR);
					return;
				}
				// @todo stack of addrs
				if (is_array($result)) {
					srand(Daemon::$process->pid);
					$real = $result[rand(0, sizeof($result) - 1)];
					srand();
				} else {
					$real = $result;
				}
				$this->connectTcp($real, $port);
			});
			return true;
		}
		$this->hostReal = $host;
		if ($this->host === null) {
			$this->host = $this->hostReal;
		}
		// TCP
		$l = strlen($pton);
		if ($l === 4) {
			$this->addr = $host . ':' . $port;
			if (!$this->bevConnectEnabled) {
				$fd = socket_create(EventUtil::AF_INET, EventUtil::SOCK_STREAM, EventUtil::SOL_TCP);
			}
		} elseif ($l === 16) {
			$this->addr = '[' . $host . ']:' . $port;
			if (!$this->bevConnectEnabled) {
				$fd = socket_create(EventUtil::AF_INET6, EventUtil::SOCK_STREAM, EventUtil::SOL_TCP);
			}
		} else {
			return false;
		}
		if (!$this->bevConnectEnabled && !$fd) {
			return false;
		}
		if (!$this->bevConnectEnabled) {
			socket_set_nonblock($fd);
		}
		if (!$this->bevConnectEnabled) {
			@socket_connect($fd, $host, $port);
			socket_getsockname($fd, $this->locAddr, $this->locPort);
		}
		else {
			$this->bevConnect = true;
		}
		$this->setFd($fd);
		if (!$this->bev) {
			return false;
		}
		return true;
	}

	/**
	 * Set keepalive
	 * @return void
	 */
	public function setKeepalive($bool) {
		$this->keepalive = (bool) $bool;
		$this->setOption(EventUtil::SOL_SOCKET, EventUtil::SO_KEEPALIVE, $this->keepalive ? true : false);
	}

	/**
	 * Set timeouts
	 * @param integer Read timeout in seconds
	 * @param integer Write timeout in seconds
	 * @return void
	 */
	public function setTimeouts($read, $write) {
		parent::setTimeouts($read, $write);
		if ($this->fd !== null) {
			$this->setOption(EventUtil::SOL_SOCKET, EventUtil::SO_SNDTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
			$this->setOption(EventUtil::SOL_SOCKET, EventUtil::SO_RCVTIMEO, ['sec' => $this->timeout, 'usec' => 0]);
		}
	}

	/**
	 * Set socket option
	 * @param integer Level
	 * @param integer Option
	 * @param mixed Value
	 * @return void
	 */
	public function setOption($level, $optname, $val) {
		if (is_resource($this->fd)) {
			socket_set_option($this->fd, $level, $optname, $val);
		} else {
			EventUtil::setSocketOption($this->fd, $level, $optname, $val);
		}
	}
}
