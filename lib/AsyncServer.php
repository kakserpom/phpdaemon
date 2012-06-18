<?php

/**
 * Asynchronous server
 *
 * @package Core
 * @deprecated (softly)
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class AsyncServer extends AppInstance {

	const TYPE_TCP    = 0;
	const TYPE_SOCKET = 1;

	protected $initialLowMark  = 1;         // initial value of the minimal amout of bytes in buffer
	protected $initialHighMark = 0xFFFFFF;  // initial value of the maximum amout of bytes in buffer
	protected $queuedReads     = FALSE;

	public $buf             = array();   // collects connection's buffers.
	public $poolState       = array();
	public $poolQueue       = array();
	public $allowedClients  = NULL;
	public $directReads     = FALSE;
	public $readPacketSize  = 4096;
	public $socketEvents    = array();
	public $readEvents      = array();

	/**
	 * Route incoming request to related application
	 * @param resource Socket
	 * @param int Type (TYPE_* constants)
	 * @param string Address
	 * @return void
	 */
	public function addSocket($sock, $type, $addr) {
		$ev = event_new();
		if (!event_set(
			$ev,
			$sock,
			EV_READ,
			array($this, 'onAcceptEvent'),
			array(Daemon::$sockCounter, $type)
		)) {
			Daemon::log(get_class($this) . '::' . __METHOD__ . ': Couldn\'t set event on binded socket: ' . Debug::dump($sock));
			return;
		}
		$k = Daemon::$sockCounter++;
		Daemon::$sockets[$k] = array($sock, $type, $addr);
		$this->socketEvents[$k] = $ev;
	}
	
	/**
	 * Enable all events of sockets
	 * @return void
	*/
	public function enableSocketEvents() {
		foreach ($this->socketEvents as $ev) {
			event_base_set($ev, Daemon::$process->eventBase);
			event_add($ev);
		}
	}
	
	/**
	 * Disable all events of sockets
	 * @return void
	 */
	public function disableSocketEvents() {  
		foreach ($this->socketEvents as $k => $ev) {
			event_del($ev);
			event_free($ev);

			unset($this->socketEvents[$k]);
		}
	}

	/**
	 * Called when application instance is going to shutdown
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown() {
		//$this->disableSocketEvents(); // very important, it causes infinite loop in baseloop.
		
		if (isset($this->sessions)) {
			$result = TRUE;
	
			foreach ($this->sessions as $k => $sess) {
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
	 * Called when the worker is ready to go
	 * @return void
	 */
	public function onReady() {
		$this->enableSocketEvents();
	}
	
	/**
	 * Bind given sockets
	 * @param mixed Addresses to bind
	 * @param integer Optional. Default port to listen
	 * @param boolean SO_REUSE. Default is true
	 * @return void
	 */
	public function bindSockets($addrs = array(), $listenport = 0, $reuse = TRUE) {
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
					Daemon::log('Unix-socket \'' . $path . '\' must has \'.sock\' extension.');
					continue;
				}
				
				if (file_exists($path)) {
					unlink($path);
				}

				if (Daemon::$useSockets) {
					$sock = socket_create(AF_UNIX, SOCK_STREAM, 0);

					if (!$sock) {
						$errno = socket_last_error();
						Daemon::log(get_class($this) . ': Couldn\'t create UNIX-socket (' . $errno . ' - ' . socket_strerror($errno) . ').');

						continue;
					}

					// SO_REUSEADDR is meaningless in AF_UNIX context

					
					if (!@socket_bind($sock, $path)) {
						$errno = socket_last_error();
						Daemon::log(get_class($this) . ': Couldn\'t bind Unix-socket \'' . $path . '\' (' . $errno . ' - ' . socket_strerror($errno) . ').');

						continue;
					}
		
					if (!socket_listen($sock, SOMAXCONN)) {
						$errno = socket_last_error();
						Daemon::log(get_class($this) . ': Couldn\'t listen UNIX-socket \'' . $path . '\' (' . $errno . ' - ' . socket_strerror($errno) . ')');
					}

					socket_set_nonblock($sock);
				} else {
					if (!$sock = @stream_socket_server('unix://' . $path, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN)) {
						Daemon::log(get_class($this) . ': Couldn\'t bind Unix-socket \'' . $path . '\' (' . $errno . ' - ' . $errstr . ').');

						continue;
					}

					stream_set_blocking($sock, 0);
				}

				chmod($path,0770);

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
						Daemon::log(get_class($this) . ': Couldn\'t create TCP-socket (' . $errno . ' - ' . socket_strerror($errno) . ').');

						continue;
					}

					if ($reuse) {
						if (!socket_set_option($sock, SOL_SOCKET, SO_REUSEADDR, 1)) {
							$errno = socket_last_error();
							Daemon::log(get_class($this) . ': Couldn\'t set option REUSEADDR to socket (' . $errno . ' - ' . socket_strerror($errno) . ').');

							continue;
						}

						if (Daemon::$reusePort && !socket_set_option($sock, SOL_SOCKET, SO_REUSEPORT, 1)) {
							$errno = socket_last_error();
							Daemon::log(get_class($this) . ': Couldn\'t set option REUSEPORT to socket (' . $errno . ' - ' . socket_strerror($errno) . ').');

							continue;
						}
					}

					if (!@socket_bind($sock, $hp[0], $hp[1])) {
						$errno = socket_last_error();
						Daemon::log(get_class($this) . ': Couldn\'t bind TCP-socket \'' . $addr . '\' (' . $errno . ' - ' . socket_strerror($errno) . ').');

						continue;
					}

					if (!socket_listen($sock, SOMAXCONN)) {
						$errno = socket_last_error();
						Daemon::log(get_class($this) . ': Couldn\'t listen TCP-socket \'' . $addr . '\' (' . $errno . ' - ' . socket_strerror($errno) . ')');

						continue;
					}

					socket_set_nonblock($sock);
				} else {
					if (!$sock = @stream_socket_server($addr, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN)) {
						Daemon::log(get_class($this) . ': Couldn\'t bind address \'' . $addr . '\' (' . $errno . ' - ' . $errstr . ')');

						continue;
					}

					stream_set_blocking($sock, 0);
				}
			}
		
			if (!is_resource($sock)) {
				Daemon::log(get_class($this) . ': Couldn\'t add errorneus socket with address \'' . $addr . '\'.');
			} else {
				$this->addSocket($sock, $type, $addr);
			}
		}
	}
	
	/**
	 * Set the size of data to read at each reading
	 * @param integer Size
	 * @return object This
	 */
	public function setReadPacketSize($n) {
		$this->readPacketSize = $n;

		return $this;
	}
	
	/**
	 * Called when remote host is trying to establish the connection
	 * @param integer Connection's ID
	 * @param string Address
	 * @return boolean Accept/Drop the connection
	 */
	public function onAccept($connId, $addr) {
		if ($this->allowedClients === NULL) {
			return TRUE;
		}
		
		if (($p = strrpos($addr, ':')) === FALSE) {
			return TRUE;
		}
		
		return $this->netMatch($this->allowedClients,substr($addr, 0, $p));
	}
	
	/**
	 * Called when remote host is trying to establish the connection
	 * @param resource Descriptor
	 * @param integer Events
	 * @param mixed Attached variable
	 * @return boolean If true then we can accept new connections, else we can't
	 */
	public function checkAccept($stream, $events, $arg) {
		if (Daemon::$process->reload) {
			return FALSE;
		}
		
		return TRUE;
	}
	
	/**
	 * Close the connection
	 * @param integer Connection's ID
	 * @return void
	 */
	public function closeConnection($connId) {
		if (Daemon::$config->logevents->value) {
			Daemon::$process->log('closeConnection(' . $connId . ').');
		}
		
		if (!isset($this->buf[$connId])) {
			return;
		}
		
		if (isset($this->readEvents[$connId])) {
			event_del($this->readEvents[$connId]);
			event_free($this->readEvents[$connId]);

			unset($this->readEvents[$connId]);
		}

		event_buffer_free($this->buf[$connId]);
		if (isset(Daemon::$process->pool[$connId])) {
			if (Daemon::$useSockets) {
				socket_close(Daemon::$process->pool[$connId]);
			} else {
				fclose(Daemon::$process->pool[$connId]);
			}
		}
		unset(Daemon::$process->pool[$connId]);
		unset(Daemon::$process->poolApp[$connId]);
		unset(Daemon::$process->readPoolState[$connId]);
		unset($this->buf[$connId]);
		unset($this->poolQueue[$connId]);
		unset($this->poolState[$connId]);
		unset(Daemon::$process->poolState[$connId]);
	}
	
	/**
	 * Establish a connection with remote peer
	 * @param string Destination Host/IP/UNIX-socket
	 * @param integer Optional. Destination port
	 * @return integer Connection's ID. Boolean false when failed.
	 */
	public function connectTo($host, $port = 0) {
		if (Daemon::$config->logevents->value) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . '(' . $host . ':' . $port . ') invoked.');
		}

		if (stripos($host, 'unix:') === 0) {
			// Unix-socket
			$e = explode(':', $host, 2);

			if (Daemon::$useSockets) {
				$conn = socket_create(AF_UNIX, SOCK_STREAM, 0);

				if (!$conn) {
					return FALSE;
				}
				
				socket_set_nonblock($conn);
				@socket_connect($conn, $e[1], 0);
			} else {
				$conn = @stream_socket_client('unix://' . $e[1]);

				if (!$conn) {
					return FALSE;
				}
				
				stream_set_blocking($conn, 0);
			}
		} 
		elseif (stripos($host, 'raw:') === 0) {
			// Raw-socket
			$e = explode(':', $host, 2);

			if (Daemon::$useSockets) {
				$conn = socket_create(AF_INET, SOCK_RAW, 1);

				if (!$conn) {
					return false;
				}
				
				socket_set_nonblock($conn);
				@socket_connect($conn, $e[1], 0);
			} else {
				return false;
			}
		} else {
			// TCP
			if (Daemon::$useSockets) {
				$conn = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

				if (!$conn) {
					return FALSE;
				}
				
				socket_set_nonblock($conn);
				@socket_connect($conn, $host, $port);
			} else {
				$conn = @stream_socket_client(($host === '') ? '' : $host . ':' . $port);

				if (!$conn) {
					return FALSE;
				}
				
				stream_set_blocking($conn, 0);
			}
		}
		
		$connId = ++Daemon::$process->connCounter;
		
		Daemon::$process->pool[$connId] = $conn;
		Daemon::$process->poolApp[$connId] = $this;
		
		$this->poolQueue[$connId] = array();
		$this->poolState[$connId] = array();

		if ($this->directReads) {
			$ev = event_new();

			if (!event_set($ev, Daemon::$process->pool[$connId], EV_READ | EV_PERSIST, array($this, 'onReadEvent'), array($connId))) {
				Daemon::log(get_class($this) . '::' . __METHOD__ . ': Couldn\'t set event on accepted socket #' . $connId);

				return;
			}

			event_base_set($ev, Daemon::$process->eventBase);
			event_add($ev);
			$this->readEvents[$connId] = $ev;
		}

		$buf = event_buffer_new(
			Daemon::$process->pool[$connId],$this->directReads ? NULL : array($this, 'onReadEvent'),
			array($this, 'onWriteEvent'),
			array($this, 'onFailureEvent'),
			array($connId)
		);
		
		if (!event_buffer_base_set($buf,Daemon::$process->eventBase)) {
			throw new Exception('Couldn\'t set base of buffer.');
		}
		
		event_buffer_priority_set($buf, 10);
		event_buffer_watermark_set($buf, EV_READ, $this->initialLowMark, $this->initialHighMark);
		event_buffer_enable($buf,$this->directReads ? (EV_WRITE | EV_PERSIST) : (EV_READ | EV_WRITE | EV_PERSIST));

		$this->buf[$connId] = $buf;

		return $connId;
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

		if (Daemon::$config->logevents->value) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . '(' . $sockId . ') invoked.');
		}
		
		if ($this->checkAccept($stream, $events, $arg)) {
			event_add($this->socketEvents[$sockId]);
		}
		
		if (Daemon::$useSockets) {
			$conn = @socket_accept($stream);

			if (!$conn) {
				return;
			}
			
			socket_set_nonblock($conn);
			
			if (Daemon::$sockets[$sockId][1] === self::TYPE_SOCKET) {
				$addr = '';
			} else {
				socket_getpeername($conn, $host, $port);
				
				$addr = ($host === '') ? '' : $host . ':' . $port;
			}
		} else {
			$conn = @stream_socket_accept($stream, 0, $addr);

			if (!$conn) {
				return;
			}
			
			stream_set_blocking($conn, 0);
		}
		
		if (!$this->onAccept(Daemon::$process->connCounter + 1, $addr)) {
			Daemon::log('Connection is not allowed (' . $addr . ')');

			if (Daemon::$useSockets) {
				socket_close($conn);
			} else {
				fclose($conn);
			}
			
			return;
		}
		
		$connId = ++Daemon::$process->connCounter;
		
		Daemon::$process->pool[$connId] = $conn;
		Daemon::$process->poolApp[$connId] = $this;
		
		$this->poolQueue[$connId] = array();
		$this->poolState[$connId] = array();

		if ($this->directReads) {
			$ev = event_new();

			if (!event_set($ev, Daemon::$process->pool[$connId], EV_READ | EV_PERSIST, array($this, 'onReadEvent'), array($connId))) {
				Daemon::log(get_class($this) . '::' . __METHOD__ . ': Couldn\'t set event on accepted socket #' . $connId);
				return;
			}

			event_base_set($ev, Daemon::$process->eventBase);
			event_add($ev);
			
			$this->readEvents[$connId] = $ev;
		}

		$buf = event_buffer_new(
			Daemon::$process->pool[$connId],
			$this->directReads ? NULL : array($this, 'onReadEvent'),
			array($this, 'onWriteEvent'),
			array($this, 'onFailureEvent'),
			array($connId)
		);
		
		if (!event_buffer_base_set($buf, Daemon::$process->eventBase)) {
			throw new Exception('Couldn\'t set base of buffer.');
		}
		
		event_buffer_priority_set($buf, 10);
		event_buffer_watermark_set($buf, EV_READ, $this->initialLowMark, $this->initialHighMark);
		event_buffer_enable($buf, $this->directReads ? (EV_WRITE | EV_PERSIST) : (EV_READ | EV_WRITE | EV_PERSIST));

		$this->buf[$connId] = $buf;
		$this->onAccepted($connId, $addr);
	}
	
	/**
	 * Called when new connection is accepted
	 * @param integer Connection's ID
	 * @param string Address of the connected peer
	 * @return void
	 */
	protected function onAccepted($connId, $addr) { }

	/**
	 * Send a data to the connection
	 * @param integer Connection's ID
	 * @param string Data to send
	 * @return boolean Success
	 */
	public function write($connId, $s) {
		Daemon::$process->writePoolState[$connId] = TRUE; 

		if (!isset($this->buf[$connId])) {
			if (isset($this->sessions[$connId])) {
				$this->sessions[$connId]->finish();
			}

			return FALSE;
		}
		
		return event_buffer_write($this->buf[$connId], $s);
	}
	
	/**
	 * Finish the connection
	 * @param integer Connection's ID
	 * @return boolean Success
	 */
	public function finishConnection($connId) {
		if (Daemon::$config->logevents->value) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . '(' . $connId . ') invoked.');
		}
		
		if (!isset($this->poolState[$connId])) {
			return FALSE;
		}
		
		if (!isset(Daemon::$process->writePoolState[$connId])) {
			$this->closeConnection($connId);
		} else {
			$this->poolState[$connId] = FALSE;
		}
	
		return TRUE;
	}
	
	/**
	 * Called when the connection has got new data
	 * @param resource Descriptor
	 * @param mixed Attacted variable
	 * @return void
	 */
	public function onReadEvent($stream, $arg) {
		$connId = is_array($arg) ? $arg[0] : array_search($stream, Daemon::$process->pool, TRUE);

		if (Daemon::$config->logevents->value) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . '(' . $connId . ') invoked. ' . Debug::dump(Daemon::$process->pool[$connId]));
		}

		if ($this->queuedReads) {
			Daemon::$process->readPoolState[$connId] = TRUE;
			Daemon_TimedEvent::setTimeout('readPoolEvent');
		}
		
		$success = FALSE;
		
		if (isset($this->sessions[$connId])) {
			if ($this->sessions[$connId]->readLocked) {
				return;
			}

			while (($buf = $this->read($connId, $this->readPacketSize)) !== FALSE) {
				$success = TRUE;
				$this->sessions[$connId]->stdin($buf);
			}
		}
	}
	
	/**
	 * Called when the connection is ready to accept new data
	 * @param resource Descriptor
	 * @param mixed Attacted variable
	 * @return void
	 */
	public function onWriteEvent($stream, $arg) {
		$connId = $arg[0];
		unset(Daemon::$process->writePoolState[$connId]);
		
		if (Daemon::$config->logevents->value) {
			Daemon::$process->log('event ' . get_class($this) . '::' . __METHOD__ . '(' . $connId . ') invoked.');
		}

		if ($this->poolState[$connId] === FALSE) {
			$this->closeConnection($connId);
		}
		
		if (Daemon::$config->logevents->value) {
			Daemon::$process->log('event ' . get_class($this) . '::' . __METHOD__ . '(' . $connId . ') finished.');
		}

		if (isset($this->sessions[$connId])) {
			$this->sessions[$connId]->onWrite();
		}
	
		if (isset($this->poolQueue[$connId])) {
			foreach ($this->poolQueue[$connId] as $r) {
				if ($r instanceof stdClass) {
					continue;
				}

				$r->onWrite();
			}
		}
	}
	
	/**
	 * Called when the connection failed
	 * @param resource Descriptor
	 * @param mixed Attacted variable
	 * @return void
	 */
	public function onFailureEvent($stream, $arg) {
		if (is_int($stream)) {
			$connId = $stream;
		}
		elseif ($this->directReads) {
			$connId = array_search($stream, Daemon::$process->pool, TRUE);
		} else {
			$connId = array_search($stream, $this->buf, TRUE);
		}
		
		if (Daemon::$config->logevents->value) {
			Daemon::$process->log('event ' . get_class($this) . '::' . __METHOD__ . '(' . $connId . ') invoked.');
		}

		$this->abortRequestsByConnection($connId);
		$this->closeConnection($connId);
		$sess = &$this->sessions[$connId];

		if ($sess) {
			if ($sess->finished) {
				return;
			}
			
			$sess->finished = TRUE;
			$sess->onFinish();
		}
		
		event_base_loopexit(Daemon::$process->eventBase);
	}
	
	/**
	 * Abort each of alive requests related with the give connection's id
	 * @param integer Connection's ID.
	 * @return void
	 */
	public function abortRequestsByConnection($connId) {
		if (!isset($this->poolQueue[$connId])) {
			return;
		}
		
		foreach ($this->poolQueue[$connId] as &$r) {
			if (!$r instanceof stdClass) {
				$r->abort();
			}
		}
	}
	
	/**
	 * Read data from the connection's buffer
	 * @param integer Connection's ID
	 * @param integer Max. number of bytes to read
	 * @return string Readed data
	 */
	public function read($connId, $n) {
		if (!isset($this->buf[$connId])) {
			return FALSE;
		}
		
		if (isset($this->readEvents[$connId])) {
			if (Daemon::$useSockets) {
				$read = socket_read(Daemon::$process->pool[$connId], $n);

				if ($read === FALSE) {
					$no = socket_last_error(Daemon::$process->pool[$connId]);

					if ($no !== 11) {  // Resource temporarily unavailable
						Daemon::log(get_class($this) . '::' . __METHOD__ . ': connId = ' . $connId . '. Socket error. (' . $no . '): ' . socket_strerror($no));
						$this->onFailureEvent($connId, array());
					}
				}
			} else {
				$read = fread(Daemon::$process->pool[$connId], $n);
			}
		} else {
			$read = event_buffer_read($this->buf[$connId], $n);
		}
		
		if (
			($read === '') 
			|| ($read === NULL) 
			|| ($read === FALSE)
		) {
			if (Daemon::$config->logreads->value) {
				Daemon::log('read(' . $connId . ',' . $n . ') interrupted.');
			}

			unset(Daemon::$process->readPoolState[$connId]);

			return FALSE;
		}
		
		if (Daemon::$config->logreads->value) {
			Daemon::log('read(' . $connId . ',' . $n . ',[' . gettype($read) . '-' . ($read === FALSE ? 'false' : strlen($read)) . ':' . Debug::exportBytes($read) . ']).');
		}

		return $read;
	}

	/**
	 * Checks if the CIDR-mask matches the IP
	 * @param string CIDR-mas
	 * @param string IP
	 * @return boolean Result
	 */
	protected function netMatch($CIDR, $IP) {
		/* TODO: IPV6 */
		if (is_array($CIDR)) {
			foreach ($CIDR as &$v) {
				if ($this->netMatch($v, $IP)) {
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
