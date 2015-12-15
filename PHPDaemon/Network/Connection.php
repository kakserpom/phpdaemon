<?php
namespace PHPDaemon\Network;

use PHPDaemon\BoundSocket\Generic;
use PHPDaemon\BoundSocket\TCP;
use PHPDaemon\Cache\CappedStorage;
use PHPDaemon\Cache\CappedStorageHits;
use PHPDaemon\Config;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Network\IOStream;
use PHPDaemon\Structures\StackCallbacks;

/**
 * Connection
 * @package PHPDaemon\Network
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
abstract class Connection extends IOStream {

	/**
	 * @var string Path
	 */
	protected $path;

	/**
	 * @var string Hostname
	 */
	protected $host;

	/**
	 * @var string Real host
	 */
	protected $hostReal;

	/**
	 * @var integer Port number
	 */
	protected $port;

	/**
	 * @var string User name
	 */
	protected $user;

	/**
	 * @var string Password
	 */
	protected $password;

	/**
	 * @var string Address
	 */
	protected $addr;

	/**
	 * @var object Stack of callbacks called when connection is established
	 */
	protected $onConnected = null;

	/**
	 * @var boolean Connected?
	 */
	protected $connected = false;

	/**
	 * @var boolean Failed?
	 */
	protected $failed = false;

	/**
	 * @var integer Timeout
	 */
	protected $timeout = 120;

	/**
	 * @var string Local address
	 */
	protected $locAddr;

	/**
	 * @var integer Local port
	 */
	protected $locPort;

	/**
	 * @var boolean Keepalive?
	 */
	protected $keepalive = false;

	/**
	 * @var string Type
	 */
	protected $type;

	/**
	 * @var Generic Parent socket
	 */
	protected $parentSocket;

	/**
	 * @var boolean Dgram connection?
	 */
	protected $dgram = false;

	/**
	 * @var boolean Enable bevConnect?
	 */
	protected $bevConnectEnabled = true;

	/**
	 * @var array URI information
	 */
	protected $uri;

	/**
	 * @var string Scheme
	 */
	protected $scheme;

	/**
	 * @var string Private key file
	 */
	protected $pkfile;

	/**
	 * @var string Certificate file
	 */
	protected $certfile;

	/**
	 * @var string Passphrase
	 */
	protected $passphrase;

	/**
	 * @var boolean Verify peer?
	 */
	protected $verifypeer = false;

	/**
	 * @var boolean Allow self-signed?
	 */
	protected $allowselfsigned = true;

	/**
	 * @var CappedStorage Context cache
	 */
	protected static $contextCache;

	/**
	 * @var number Context cache size
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
	 * Sets DGRAM mode
	 * @param  boolean $bool DGRAM Mode
	 * @return void
	 */
	public function setDgram($bool) {
		$this->dgram = $bool;
	}

	/**
	 * Sets peer name
	 * @param  string  $host Hostname
	 * @param  integer $port Port
	 * @return void
	 */
	public function setPeername($host, $port) {
		$this->host = $host;
		$this->port = $port;
		$this->addr = '[' . $this->host . ']:' . $this->port;
		if ($this->pool->allowedClients !== null) {
			if (!TCP::netMatch($this->pool->allowedClients, $this->host)) {
				Daemon::log('Connection is not allowed (' . $this->host . ')');
				$this->ready = false;
				$this->finish();
			}
		}
	}

	/**
	 * Getter
	 * @param  string $name Name
	 * @return mixed
	 */
	public function __get($name) {
		if (   $name === 'connected'
			|| $name === 'hostReal'
			|| $name === 'host'
			|| $name === 'port'
		) {
			return $this->{$name};
		}
		return parent::__get($name);
	}

	/**
	 * Get socket name
	 * @param  string &$addr Addr
	 * @param  srting &$port Port
	 * @return void
	 */
	public function getSocketName(&$addr, &$port) {
		if (func_num_args() === 0) {
			\EventUtil::getSocketName($this->bev->fd, $this->locAddr, $this->locPort);
			return;
		}
		\EventUtil::getSocketName($this->bev->fd, $addr, $port);
	}

	/**
	 * Sets parent socket
	 * @param \PHPDaemon\BoundSocket\Generic $sock
	 * @return void
	 */
	public function setParentSocket(Generic $sock) {
		$this->parentSocket = $sock;
	}

	/**
	 * Called when new UDP packet received
	 * @param  object $pct Packet
	 * @return void
	 */
	public function onUdpPacket($pct) {
	}

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
	 * @param  Request $req Parent Request
	 * @return void
	 */
	public function onInheritanceFromRequest($req) {
	}

	/**
	 * Called when the connection failed to be established
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
	 * @param  EventBufferEvent $bev
	 * @return void
	 */
	public function onFailureEv($bev = null) {
		try {
			if (!$this->connected && !$this->failed) {
				$this->failed = true;
				$this->onFailure();
			}
			$this->connected = false;;
		} catch (\Exception $e) {
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
	 * @param  string  $data Data to send
	 * @return boolean       Success
	 */
	public function write($data) {
		if ($this->dgram) {
			return $this->parentSocket->sendTo($data, $this->finished ? MSG_EOF : 0, $this->host, $this->port);
		}
		return parent::write($data);
	}

	/**
	 * Executes the given callback when/if the connection is handshaked
	 * @param  callable $cb Callback
	 * @return void
	 */
	public function onConnected($cb) {
		if ($this->connected) {
			call_user_func($cb, $this);
		}
		else {
			if (!$this->onConnected) {
				$this->onConnected = new StackCallbacks;
			}
			$this->onConnected->push($cb);
		}
	}

	protected function importParams() {
		foreach ($this->uri['params'] as $key => $val) {
			if (isset($this->{$key}) && is_bool($this->{$key})) {
				$this->{$key} = (bool)$val;
				continue;
			}
			if (!property_exists($this, $key)) {
				Daemon::log(get_class($this) . ': unrecognized setting \'' . $key . '\'');
				continue;
			}
			$this->{$key} = $val;
		}
		if (!$this->ctxname) {
			return;
		}
		if (!isset(Daemon::$config->{'TransportContext:' . $this->ctxname})) {
			Daemon::log(get_class($this) . ': undefined transport context \'' . $this->ctxname . '\'');
			return;
		}
		$ctx = Daemon::$config->{'TransportContext:' . $this->ctxname};
		foreach ($ctx as $key => $entry) {
			$value = ($entry instanceof Config\Entry\Generic) ? $entry->value : $entry;
			if (isset($this->{$key}) && is_bool($this->{$key})) {
				$this->{$key} = (bool)$value;
				continue;
			}
			if (!property_exists($this, $key)) {
				Daemon::log(get_class($this) . ': unrecognized setting in transport context \'' . $this->ctxname . '\': \'' . $key . '\'');
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
		if (!\EventUtil::sslRandPoll()) {
			Daemon::$process->log(get_class($this->pool) . ': EventUtil::sslRandPoll failed');
			return false;
		}
		$params = [
			\EventSslContext::OPT_VERIFY_PEER       => $this->verifypeer,
			\EventSslContext::OPT_ALLOW_SELF_SIGNED => $this->allowselfsigned,
		];
		if ($this->certfile !== null) {
			$params[\EventSslContext::OPT_LOCAL_CERT] = $this->certfile;
		}
		if ($this->pkfile !== null) {
			$params[\EventSslContext::OPT_LOCAL_PK] = $this->pkfile;
		}
		if ($this->passphrase !== null) {
			$params[\EventSslContext::OPT_PASSPHRASE] = $this->passphrase;
		}
		$hash = igbinary_serialize($params);
		if (!self::$contextCache) {
			self::$contextCache = new CappedStorageHits(self::$contextCacheSize);
		}
		elseif ($ctx = self::$contextCache->getValue($hash)) {
			return $ctx;
		}
		$ctx = new \EventSslContext(\EventSslContext::SSLv3_CLIENT_METHOD, $params);
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
	 * @return integer
	 */
	public function getPort() {
		return $this->port;
	}

	/**
	 * Connects to URL
	 * @param  string   $url URL
	 * @param  callable $cb  Callback
	 * @return boolean       Success
	 */
	public function connect($url, $cb = null) {
		$this->uri = Config\Object::parseCfgUri($url);
		$u         =& $this->uri;
		if (!$u) {
			return false;
		}
		$this->importParams();
		if (!isset($u['port'])) {
			if ($this->ssl) {
				if (isset($this->pool->config->sslport->value)) {
					$u['port'] = $this->pool->config->sslport->value;
				}
			}
			else {
				if (isset($this->pool->config->port->value)) {
					$u['port'] = $this->pool->config->port->value;
				}
			}
		}
		if (isset($u['user'])) {
			$this->user = $u['user'];
		}

		if ($this->ssl) {
			$this->setContext($this->initSSLContext(), \EventBufferEvent::SSL_CONNECTING);
		}

		$this->url    = $url;
		$this->scheme = strtolower($u['scheme']);
		$this->host   = isset($u['host']) ? $u['host'] : null;
		$this->port   = isset($u['port']) ? $u['port'] : 0;

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
		Daemon::log(get_class($this) . ': connect(): unrecoginized scheme \'' . $this->scheme . '\' (not unix/raw/udp/tcp) in URL: ' . $url);
		return false;
	}

	/**
	 * Establish UNIX socket connection
	 * @param  string  $path Path
	 * @return boolean       Success
	 */
	public function connectUnix($path) {
		$this->type = 'unix';

		if (!$this->bevConnectEnabled) {
			$fd = socket_create(AF_UNIX, SOCK_STREAM, 0);
			if (!$fd) {
				return false;
			}
			socket_set_nonblock($fd);
			@socket_connect($fd, $path, 0);
			$this->setFd($fd);
			return true;
		}
		$this->bevConnect = true;
		$this->addr       = 'unix:' . $path;
		$this->setFd(null);
		return true;
	}

	/**
	 * Establish raw socket connection
	 * @param  string  $host Hostname
	 * @return boolean       Success
	 */
	public function connectRaw($host) {
		$this->type = 'raw';
		if (@inet_pton($host) === false) { // dirty check
			\PHPDaemon\Clients\DNS\Pool::getInstance()->resolve($host, function ($result) use ($host) {
				if ($result === false) {
					Daemon::log(get_class($this) . '->connectRaw : enable to resolve hostname: ' . $host);
					$this->onFailureEv();
					return;
				}
				// @TODO stack of addrs
				if (is_array($result)) {
					srand(Daemon::$process->getPid());
					$real = $result[rand(0, sizeof($result) - 1)];
					srand();
				}
				else {
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
		$fd         = socket_create(\EventUtil::AF_INET, \EventUtil::SOCK_RAW, 1);
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

	/**
	 * Establish UDP connection
	 * @param  string  $host Hostname
	 * @param  integer $port Port
	 * @return boolean       Success
	 */
	public function connectUdp($host, $port) {
		$this->type = 'udp';
		$pton       = @inet_pton($host);
		if ($pton === false) { // dirty check
			\PHPDaemon\Clients\DNS\Pool::getInstance()->resolve($host, function ($result) use ($host, $port) {
				if (!$result) {
					Daemon::log(get_class($this) . '->connectUdp : enable to resolve hostname: ' . $host);
					$this->onStateEv($this->bev, \EventBufferEvent::ERROR);
					return;
				}
				// @todo stack of addrs
				if (is_array($result)) {
					srand(Daemon::$process->getPid());
					$real = $result[rand(0, sizeof($result) - 1)];
					srand();
				}
				else {
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
			/* @TODO: use EventUtil::SOCK_DGRAM */
			$fd = socket_create(\EventUtil::AF_INET, SOCK_DGRAM, \EventUtil::SOL_UDP);
		}
		elseif ($l === 16) {
			$this->addr = '[' . $host . ']:' . $port;
			$fd         = socket_create(\EventUtil::AF_INET6, SOCK_DGRAM, \EventUtil::SOL_UDP);
		}
		else {
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

	/**
	 * Establish TCP connection
	 * @param  string  $host Hostname
	 * @param  integer $port Port
	 * @return boolean       Success
	 */
	public function connectTcp($host, $port) {
		$this->type = 'tcp';
		$pton       = @inet_pton($host);
		$fd         = null;
		if ($pton === false) { // dirty check
			\PHPDaemon\Clients\DNS\Pool::getInstance()->resolve($this->host, function ($result) use ($host, $port) {
				if (!$result) {
					Daemon::log(get_class($this) . '->connectTcp : unable to resolve hostname: ' . $host);
					$this->onStateEv($this->bev, \EventBufferEvent::ERROR);
					return;
				}
				// @todo stack of addrs
				if (is_array($result)) {
					if (!sizeof($result)) {
						return;
					}
					srand(Daemon::$process->getPid());
					$real = $result[rand(0, sizeof($result) - 1)];
					srand();
				}
				else {
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
				$fd = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
			}
		}
		elseif ($l === 16) {
			$this->addr = '[' . $host . ']:' . $port;
			if (!$this->bevConnectEnabled) {
				$fd = socket_create(AF_INET6, SOCK_STREAM, SOL_TCP);
			}
		}
		else {
			return false;
		}
		if (!$this->bevConnectEnabled && !$fd) {
			return false;
		}
		if (!$this->bevConnectEnabled) {
			socket_set_nonblock($fd);
		}
		if (!$this->bevConnectEnabled) {
			$this->fd = $fd;
			$this->setTimeouts($this->timeoutRead !== null ? $this->timeoutRead : $this->timeout,
							$this->timeoutWrite!== null ? $this->timeoutWrite : $this->timeout);
			socket_connect($fd, $host, $port);
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
	 * @param  boolean $bool
	 * @return void
	 */
	public function setKeepalive($bool) {
		$this->keepalive = (bool)$bool;
		$this->setOption(\EventUtil::SOL_SOCKET, \EventUtil::SO_KEEPALIVE, $this->keepalive ? true : false);
	}

	/**
	 * Close the connection
	 * @return void
	 */
	public function close() {
		parent::close();
		if (is_resource($this->fd)) {
			socket_close($this->fd);
		}
	}

	/**
	 * Set timeouts
	 * @param  integer $read  Read timeout in seconds
	 * @param  integer $write Write timeout in seconds
	 * @return void
	 */
	public function setTimeouts($read, $write) {
		parent::setTimeouts($read, $write);
		if ($this->fd !== null) {
			$this->setOption(\EventUtil::SOL_SOCKET, \EventUtil::SO_SNDTIMEO, ['sec' => $this->timeoutWrite, 'usec' => 0]);
			$this->setOption(\EventUtil::SOL_SOCKET, \EventUtil::SO_RCVTIMEO, ['sec' => $this->timeoutRead, 'usec' => 0]);
		}
	}

	/**
	 * Set socket option
	 * @param  integer $level   Level
	 * @param  integer $optname Option
	 * @param  mixed   $val     Value
	 * @return void
	 */
	public function setOption($level, $optname, $val) {
		if (is_resource($this->fd)) {
			socket_set_option($this->fd, $level, $optname, $val);
		}
		else {
			\EventUtil::setSocketOption($this->fd, $level, $optname, $val);
		}
	}

	/**
	 * Called when connection finishes
	 * @return void
	 */
	public function onFinish() {
		if (!$this->connected) {
			if ($this->onConnected) {
				$this->onConnected->executeAll($this);
				$this->onConnected = null;
			}
		}
		parent::onFinish();
	}
}
