<?php
// @todo Make it more abstract
/**
 * IOStram
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
abstract class IOStream {

	public $buf = '';
	public $EOL = "\n";

	public $readPacketSize  = 8192;
	public $bev;
	public $fd;
	public $finished = false;
	public $ready = false;
	public $readLocked = false;
	public $sending = true;
	public $reading = false;
	protected $lowMark  = 1;         // initial value of the minimal amout of bytes in buffer
	protected $highMark = 0xFFFF;  	// initial value of the maximum amout of bytes in buffer
	public $priority;
	public $inited = false;
	public $state = 0;             // stream state of the connection (application protocol level)
	const STATE_ROOT = 0;
	public $onWriteOnce;
	public $timeout = null;
	public $url;
	public $alive = false; // alive?
	public $pool;
	public $bevConnect = false;

	/**
	 * IOStream constructor
 	 * @param resource File descriptor.
	 * @param object AppInstance
	 * @return void
	 */
	public function __construct($fd = null, $pool = null) {
		if ($pool) {
			$this->pool = $pool;
			$this->pool->attachConn($this);
		}
	
		if ($fd !== null) {
			$this->setFd($fd);
		}

		$this->onWriteOnce = new SplStack;
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
	
	public function setOnRead($cb) {
		$this->onRead = Closure::bind($cb, $this);
		return $this;
	}
	public function setOnWrite($cb) {
		$this->onWrite = Closure::bind($cb, $this);
		return $this;
	}
	
	public function setFd($fd) {
		$this->fd = $fd;
		$this->bev = bufferevent_socket_new(Daemon::$process->eventBase, $this->fd, EVENT_BEV_OPT_CLOSE_ON_FREE | EVENT_BEV_OPT_DEFER_CALLBACKS);
		if (!$this->bev) {
			return;
		}
		bufferevent_setcb($this->bev, array($this, 'onReadEvent'), array($this, 'onWriteEvent'), array($this, 'onStateEvent'));
		if ($this->priority !== null) {
			bufferevent_priority_set($this->bev, $this->priority);
		}
		if ($this->timeout !== null) {
			bufferevent_set_timeouts($this->bev, $this->timeout, $this->timeout);
		}
		bufferevent_setwatermark($this->bev, EVENT_READ, $this->lowMark, $this->highMark);
		bufferevent_enable($this->bev, EVENT_WRITE | EVENT_TIMEOUT | EVENT_PERSIST);
		if ($this->bevConnect && ($this->fd === null)) {
			//bufferevent_socket_connect($this->bev, $this->addr, false);
			bufferevent_socket_connect_hostname($this->bev, Daemon::$process->dnsBase, $this->hostReal, $this->port, EVENT_AF_UNSPEC);
		}
		if (!$this->inited) {
			$this->inited = true;
			$this->init();
		}
	}

	public function setTimeout($timeout) {
		$this->timeout = $timeout;
		if ($this->timeout !== null) {
			if ($this->bev) {
				bufferevent_set_timeouts($this->bev, $this->timeout, $this->timeout);
			}
		}
	}
	
	public function setPriority($p) {
		$this->priority = $p;
		event_buffer_priority_set($this->bev, $p);
	}
	
	public function setWatermark($low = null, $high = null) {
		if ($low != null) {
			$this->lowMark = $low;
		}
		if ($high != null) {
		 	$this->highMark = $high;
		}
		bufferevent_setwatermark($this->bev, EVENT_READ, $this->lowMark, $this->highMark);
	}
	
	/**
	 * Called when the session constructed
	 * @todo +on & -> protected?
	 * @return void
	 */
	public function init() {}

	/**
	 * Read a first line ended with \n from buffer, removes it from buffer and returns the line
	 * @return string Line. Returns false when failed to get a line
	 */
	public function gets() {
		$p = strpos($this->buf, $this->EOL);

		if ($p === false) {
			return false;
		}

		$sEOL = strlen($this->EOL);
		$r = binarySubstr($this->buf, 0, $p + $sEOL);
		$this->buf = binarySubstr($this->buf, $p + $sEOL);

		return $r;
	}

	public function readFromBufExact($n) {
		if ($n === 0) {
			return '';
		}
		if (strlen($this->buf) < $n) {
			return false;
		} else {
			$r = binarySubstr($this->buf, 0, $n);
			$this->buf = binarySubstr($this->buf, $n);
			return $r;
		}
	}

	/**
	 * Called when the worker is going to shutdown
	 * @return boolean Ready to shutdown?
	 */
	public function gracefulShutdown() {
		$this->finish();

		return true;
	}

	/** 
	 * Lock read
	 * @todo add more description
	 * @return void
	 */
	public function lockRead() {
		$this->readLocked = true;
	}

	/**
	 * Lock read
	 * @todo more description
	 * @return void
	 */
	public function unlockRead() {
		if (!$this->readLocked) {
			return;
		}

		$this->readLocked = false;
		$this->onReadEvent(null);
	}

	/**
	 * Called when the connection is ready to accept new data
	 * @todo protected?
	 * @return void
	 */
	public function onWrite() { }

	/**
	 * Send data to the connection. Note that it just writes to buffer that flushes at every baseloop
	 * @param string Data to send.
	 * @return boolean Success.
	 */
	public function write($data) {
		if (!$this->alive) {
			Daemon::log('Attempt to write to dead IOStream ('.get_class($this).')');
			return false;
		}
		if (!isset($this->bev)) {
			return false;
		}
		if (!strlen($data)) {
			return true;
		}
 		$this->sending = true;
		bufferevent_write($this->bev, $data);
		return true;
	}

	/**
	 * Send data and appending \n to connection. Note that it just writes to buffer that flushes at every baseloop
	 * @param string Data to send.
	 * @return boolean Success.
	 */
	public function writeln($s) {
		return $this->write($s . $this->EOL);
	}
	
	/**
	 * Send data to the connection. Note that it just writes to buffer that flushes at every baseloop
	 * @param string Data to send.
	 * @return boolean Success.
	 */
	public function send($s) {
		return $this->write($s);
	}

	/**
	 * Send data and appending \n to connection. Note that it just writes to buffer that flushes at every baseloop
	 * @param string Data to send.
	 * @return boolean Success.
	 */
	public function sendln($s) {
		return $this->writeln($s);
	}

	/**
	 * Finish the session. You shouldn't care about pending buffers, it will be flushed properly.
	 * @return void
	 */
	public function finish() {
		if ($this->finished) {
			return;
		}
		$this->finished = true;
		$this->onFinish();
		if ($this->pool) {
			$this->pool->detach($this);
		}
		if (!$this->sending) {
			$this->close();
		}
		return true;
	}

	/**
	 * Called when the session finished
	 * @todo protected?
	 * @return void
	 */
	public function onFinish() {
	}

	/**
	 * Called when new data received
	 * @todo +on & -> protected?
	 * @param string New received data
	 * @return void
	 */
	public function stdin($buf) {}
	
	/**
	 * Close the connection
	 * @param integer Connection's ID
	 * @return void
	 */
	public function close() {
		if (isset($this->bev) && is_resource($this->bev)) {
			bufferevent_free($this->bev);
			$this->bev = null;
		}
		if (isset($this->fd)) {
			$this->closeFd();
		}
	}
	
	public function closeFd() {}
	
	/**
	 * Called when the connection has got new data
	 * @param resource Bufferevent
	 * @return void
	 */
	public function onReadEvent($bev) {
		if ($this->readLocked) {
			return;
		}
		try {
			if (isset($this->onRead)) {
				$this->reading = !call_user_func($this->onRead);
			} else {
				$this->reading = !$this->onRead();
			}
		} catch (Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
	}
	
	public function onRead() {
		while (($buf = $this->read($this->readPacketSize)) !== false) {
			$this->stdin($buf);
			if ($this->readLocked) {
				return true;
			}
		}
		return true;
	}
	
	/**
	 * Called when the stream is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
	}
	
	public function onWriteOnce($cb) {
		if (!$this->sending) {
			call_user_func($cb, $this);
			return;
		}
		$this->onWriteOnce->push($cb);
	}
	/**
	 * Called when the connection is ready to accept new data
	 * @param resource Bufferedevent
	 * @param mixed Attached variable
	 * @return void
	 */
	public function onWriteEvent($bev) {
		$this->sending = false;
		if ($this->finished) {
			$this->close();
			return;
		}
		if (!$this->ready) {
			$this->ready = true;
			while (!$this->onWriteOnce->isEmpty()) {
				try {
					call_user_func($this->onWriteOnce->pop(), $this);
				} catch (Exception $e) {
					Daemon::uncaughtExceptionHandler($e);
				}
				if (!$this->ready) {
					return;
				}
			}
			$this->alive = true;
			if (isset($this->bev)) {
				event_buffer_enable($this->bev, EVENT_READ | EVENT_WRITE | EVENT_TIMEOUT | EVENT_PERSIST);
			}
			try {			
				$this->onReady();
			} catch (Exception $e) {
				Daemon::uncaughtExceptionHandler($e);
			}
		} else {
			while (!$this->onWriteOnce->isEmpty()) {
				call_user_func($this->onWriteOnce->pop(), $this);
			}
		}
		try {
			if (isset($this->onWrite)) {
				call_user_func($this->onWrite, $this);
			} else {
				$this->onWrite();
			}
		} catch (Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
	}
	
	/**
	 * Called when the connection failed
	 * @param resource Bufferevent
	 * @param int Events
	 * @param mixed Attached variable
	 * @return void
	 */
	public function onStateEvent($bev, $events) {
		if ($events & EVENT_BEV_EVENT_CONNECTED) {
			$this->onWriteEvent($bev);
		}
  		elseif ($events & (EVENT_BEV_EVENT_ERROR | EVENT_BEV_EVENT_EOF)) {
			try {
				$this->close();
				if ($this->finished) {
					return;
				}
				$this->finished = true;
				$this->onFinish();
				if ($this->pool) {
					$this->pool->detach($this);
				}
				event_base_loopexit(Daemon::$process->eventBase);
			} catch (Exception $e) {
				Daemon::uncaughtExceptionHandler($e);
			}
		}
	}
	
	/**
	 * Read data from the connection's buffer
	 * @param integer Max. number of bytes to read
	 * @return string Readed data
	 */
	public function read($n) {
		if ($this->bev === null) {
			return false;
		}
		$r = bufferevent_read($this->bev, $read, $n);
		if (
			($read === '') 
			|| ($read === null) 
			|| ($read === false)
		) {
			$this->reading = false;
			return false;
		}
		return $read;
	}
	
	public function __destruct() {
		$this->close();
	}
}
