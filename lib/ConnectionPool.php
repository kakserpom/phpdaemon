<?php

/**
 * Pool of connections
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ConnectionPool {

	const TYPE_TCP    = 0;
	const TYPE_SOCKET = 1;
	public $allowedClients  = NULL;
	public $socketEvents    = array();
	public $connectionClass;
	public $list = array();
	public $name;
	public $config;
	public static $instances = array();
	public $socketsEnabled = false;
	
	public function __construct($config = array()) {
		$this->config = $config;
		$this->onConfigUpdated();
		if ($this->connectionClass === null) {
			$this->connectionClass = get_class($this) . 'Connection';
		}
		if (isset($this->config->listen)) {
			$this->bind($this->config->listen->value, isset($this->config->port->value) ? $this->config->port->value : null);
		}		
		$this->init();
	}
	
	public function init() {
	}
	
	
	/**
	 * Called when the worker is ready to go.
	 * @return void
	*/
	public function onReady() {
		$this->enable();
	}
	
	
	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		if ($defaults = $this->getConfigDefaults()) {
			$this->processDefaultConfig($defaults);
		}
		if ($this->config === null) {
			return;
		}
		$this->applyConfig();
	}
	
	public function applyConfig() {
		foreach ($this->config as $k => $v) {
			if (is_object($v) && $v instanceof Daemon_ConfigEntry) {
				$v = $v->value;
			}
			$k = strtolower($k);
			if ($k === 'connectionclass') {
				$this->connectionClass = $v;
			}
			elseif ($k === 'name') {
				$this->name = $v;
			}
			elseif ($k === 'allowedclients') {
				$this->allowedClients = $v;
			}
			elseif ($k === 'maxallowedpacket') {
				$this->maxAllowedPacket = $v;
			}
		}		
	}
	/**
	 * Setting default config options
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return false;
	}
	
	public static function getInstance($arg = '') {
		if ($arg === 'default') {
			$arg = '';
		}
		$class = get_called_class();
		if (is_string($arg)) {
			$key = $class . ':' . $arg;
			if (isset(self::$instances[$key])) {
				return self::$instances[$key];
			}
			$k = 'Pool:' . $class . ($arg !== '' ? ':' . $arg : '' );
			
			$config = (isset(Daemon::$config->{$k}) && Daemon::$config->{$k} instanceof Daemon_ConfigSection) ? Daemon::$config->{$k}: new Daemon_ConfigSection;			
			$obj = self::$instances[$key] = new $class($config);
			$obj->name = $arg;
			return $obj;
		} else {
			return new $class(new Daemon_ConfigSection($arg));
		}
	}
	
	
 	/**
	 * Process default config
	 * @todo move it to Daemon_Config class
	 * @param array {"setting": "value"}
	 * @return void
	 */
	public function processDefaultConfig($settings = array()) {
		foreach ($settings as $k => $v) {
			$k = strtolower(str_replace('-', '', $k));

			if (!isset($this->config->{$k})) {
			  if (is_scalar($v))	{
					$this->config->{$k} = new Daemon_ConfigEntry($v);
				} else {
					$this->config->{$k} = $v;
				}
			} elseif ($v instanceof Daemon_ConfigSection) {
			// @todo
			}	else {
				$current = $this->config->{$k};
			  if (is_scalar($v))	{
					$this->config->{$k} = new Daemon_ConfigEntry($v);
				} else {
					$this->config->{$k} = $v;
				}
				
				$this->config->{$k}->setHumanValue($current->value);
				$this->config->{$k}->source = $current->source;
				$this->config->{$k}->revision = $current->revision;
			}
		}
	}
	
	public function setConnectionClass($class) {
		$this->connectionClass = $class;
	}

	/**
	 * Route incoming request to related application
	 * @param resource Socket
	 * @param int Type (TYPE_* constants)
	 * @param string Address
	 * @return void
	 */
	public function addSocket($sock, $type, $addr) {
		$ev = event_new();
		
		if (!event_set($ev,	$sock, EV_READ | EV_PERSIST, array($this, 'onAcceptEvent'), array(Daemon::$sockCounter, $type))) {
			
			Daemon::log(get_class($this) . '::' . __METHOD__ . ': Couldn\'t set event on binded socket: ' . Debug::dump($sock));
			return;
		}
		$k = Daemon::$sockCounter++;
		Daemon::$sockets[$k] = array($sock, $type, $addr);
		Daemon::$socketEvents[$k] = $ev;
		$this->socketEvents[$k] = $ev;
		if ($this->socketsEnabled) {
			event_base_set($ev, Daemon::$process->eventBase);
			event_add($ev);
		}
	}
	
	/**
	 * Enable socket events
	 * @return void
	*/
	public function enable() {
		if ($this->socketsEnabled) {
			return;
		}
		$this->socketsEnabled = true;
		foreach ($this->socketEvents as $ev) {
			event_base_set($ev, Daemon::$process->eventBase);
			event_add($ev);
		}
	}
	
	/**
	 * Disable all events of sockets
	 * @return void
	 */
	public function disable() { 
		return; // possible critical bug
		for (;sizeof($this->socketEvents);) {
			if (!is_resource($ev = array_pop($this->socketEvents))) {
				continue;
			}
			event_del($ev);
			event_free($ev);
		}
	}

	/**
	 * Called when application instance is going to shutdown
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown() {
		$this->disable(); 
		
		if (isset($this->list)) {
			$result = TRUE;
	
			foreach ($this->list as $k => $sess) {
				if (!is_object($sess)) {
					unset($this->sessions[$k]); 
					continue;
				}

				if (!$sess->gracefulShutdown()) {
					$result = FALSE;
				}
			}

			return $result;
		}

		return TRUE;
	}
	
	/**
	 * Bind given sockets
	 * @param mixed Addresses to bind
	 * @param integer Optional. Default port to listen
	 * @param boolean SO_REUSE. Default is true
	 * @return void
	 */
	public function bind($addrs = array(), $listenport = 0, $reuse = TRUE) {
		if (is_string($addrs)) {
			$addrs = explode(',', $addrs);
		}
		
		for ($i = 0, $s = sizeof($addrs); $i < $s; ++$i) {
			$addr = trim($addrs[$i]);
	
			if (stripos($addr, 'unix:') === 0) {
				$type = self::TYPE_SOCKET;
				$e = explode(':', $addr, 4);

				if (sizeof($e) == 4) {
					$user = $e[1];
					$group = $e[2];
					$path = $e[3];
				}
				elseif (sizeof($e) == 3) {
					$user = $e[1];
					$group = FALSE;
					$path = $e[2];
				} else {
					$user = FALSE;
					$group = FALSE;
					$path = $e[1];
				}

				if (pathinfo($path, PATHINFO_EXTENSION) !== 'sock') {
					Daemon::$process->log('Unix-socket \'' . $path . '\' must has \'.sock\' extension.');
					continue;
				}
				
				if (file_exists($path)) {
					unlink($path);
				}

				if (Daemon::$useSockets) {
					$sock = socket_create(AF_UNIX, SOCK_STREAM, 0);

					if (!$sock) {
						$errno = socket_last_error();
						Daemon::$process->log(get_class($this) . ': Couldn\'t create UNIX-socket (' . $errno . ' - ' . socket_strerror($errno) . ').');

						continue;
					}

					// SO_REUSEADDR is meaningless in AF_UNIX context

					if (!@socket_bind($sock, $path)) {
						$errno = socket_last_error();
						Daemon::$process->log(get_class($this) . ': Couldn\'t bind Unix-socket \'' . $path . '\' (' . $errno . ' - ' . socket_strerror($errno) . ').');

						continue;
					}
		
					if (!socket_listen($sock, SOMAXCONN)) {
						$errno = socket_last_error();
						Daemon::$process->log(get_class($this) . ': Couldn\'t listen UNIX-socket \'' . $path . '\' (' . $errno . ' - ' . socket_strerror($errno) . ')');
					}

					socket_set_nonblock($sock);
				} else {
					if (!$sock = @stream_socket_server('unix://' . $path, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN)) {
						Daemon::$process->log(get_class($this) . ': Couldn\'t bind Unix-socket \'' . $path . '\' (' . $errno . ' - ' . $errstr . ').');

						continue;
					}

					stream_set_blocking($sock, 0);
				}

				chmod($path, 0770);

				if (
					($group === FALSE) 
					&& isset(Daemon::$config->group->value)
				) {
					$group = Daemon::$config->group->value;
				}

				if ($group !== FALSE) {
					if (!@chgrp($path, $group)) {
						unlink($path);
						Daemon::log('Couldn\'t change group of the socket \'' . $path . '\' to \'' . $group . '\'.');

						continue;
					}
				}
				
				if (
					($user === FALSE) 
					&& isset(Daemon::$config->user->value)
				) {
					$user = Daemon::$config->user->value;
				}

				if ($user !== FALSE) {
					if (!@chown($path, $user)) {
						unlink($path);
						Daemon::log('Couldn\'t change owner of the socket \'' . $path . '\' to \'' . $user . '\'.');

						continue;
					}
				}
			} else {
				$type = self::TYPE_TCP;
		
				if (stripos($addr,'tcp://') === 0) {
					$addr = substr($addr, 6);
				}

				$hp = explode(':', $addr, 2);
				
				if (!isset($hp[1])) {
					$hp[1] = $listenport;
				}

				$addr = $hp[0] . ':' . $hp[1];

				if (Daemon::$useSockets) {
					$sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

					if (!$sock) {
						$errno = socket_last_error();
						Daemon::$process->log(get_class($this) . ': Couldn\'t create TCP-socket (' . $errno . ' - ' . socket_strerror($errno) . ').');

						continue;
					}

					if ($reuse) {
						if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1)) {
							$errno = socket_last_error();
							Daemon::$process->log(get_class($this) . ': Couldn\'t set option REUSEADDR to socket (' . $errno . ' - ' . socket_strerror($errno) . ').');

							continue;
						}

						if (Daemon::$reusePort && !socket_set_option($sock, SOL_SOCKET, SO_REUSEPORT, 1)) {
							$errno = socket_last_error();
							Daemon::$process->log(get_class($this) . ': Couldn\'t set option REUSEPORT to socket (' . $errno . ' - ' . socket_strerror($errno) . ').');

							continue;
						}
					}

					if (!@socket_bind($sock, $hp[0], $hp[1])) {
						$errno = socket_last_error();
						Daemon::$process->log(get_class($this) . ': Couldn\'t bind TCP-socket \'' . $addr . '\' (' . $errno . ' - ' . socket_strerror($errno) . ').');

						continue;
					}

					if (!socket_listen($sock, SOMAXCONN)) {
						$errno = socket_last_error();
						Daemon::$process->log(get_class($this) . ': Couldn\'t listen TCP-socket \'' . $addr . '\' (' . $errno . ' - ' . socket_strerror($errno) . ')');

						continue;
					}

					socket_set_nonblock($sock);
				} else {
					if (!$sock = @stream_socket_server($addr, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN)) {
						Daemon::$process->log(get_class($this) . ': Couldn\'t bind address \'' . $addr . '\' (' . $errno . ' - ' . $errstr . ')');

						continue;
					}

					stream_set_blocking($sock, 0);
				}
			}
		
			if (!is_resource($sock)) {
				Daemon::$process->log(get_class($this) . ': Couldn\'t add errorneus socket with address \'' . $addr . '\'.');
			} else {
				$this->addSocket($sock, $type, $addr);
			}
		}
	}
	
	public function removeConnection($id) {
		$conn = $this->getConnectionById($id);
		if (!$conn) {
			return false;
		}
		$conn->onFinish();
		unset($this->list[$id]);
	}

	/**
	 * Establish a connection with remote peer
	 * @param string URL
	 * @param callback Optional. Callback.
	 * @param string Optional. Connection class name.
	 * @return integer Connection's ID. Boolean false when failed.
	 */
	public function connect($url, $cb, $class = null) {
		if ($class === null) {
			$class = $this->connectionClass;
		}
		$id = ++Daemon::$process->connCounter;
		$conn = $this->list[$id] = new $class(null, $id, $this);
		$conn->connect($url, $cb);
		return $conn;
	}

	//$this->config->defaultport->value
	public function getConnectionById($id) {
		if (!isset($this->list[$id])) {
			return false;
		}
		return $this->list[$id];
 	}

	/**
	 * Called when new connections is waiting for accept
	 * @param resource Descriptor
	 * @param integer Events
	 * @param mixed Attached variable
	 * @return void
	 */
	public function onAcceptEvent($stream, $events, $arg) {
		$sockId = $arg[0];
		$type = $arg[1];
		if (Daemon::$config->logevents->value) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . '(' . $sockId . ') invoked.');
		}
		
		if (Daemon::$process->reload) {
			return FALSE;
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
		
		$id = ++Daemon::$process->connCounter;
		
		$class = $this->connectionClass;
 		$conn = new $class($fd, $id, $this);
		$this->list[$id] = $conn;

		if (Daemon::$useSockets && ($type !== self::TYPE_SOCKET)) {
			$getpeername = function($conn) use (&$getpeername) { 
				$r = @socket_getpeername($conn->fd, $host, $port);
				if ($r === false) {
    				if (109 === socket_last_error()) { // interrupt
    					if ($this->allowedClients !== null) {
    						$conn->ready = false; // lockwait
    					}
    					$conn->onWriteOnce($handler);
    					return;
    				}
    			}
				$conn->addr = $host;
				$conn->port =  $port;
				if ($conn->pool->allowedClients !== null) {
					if (!ConnectionPool::netMatch($conn->pool->allowedClients, $host)) {
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
