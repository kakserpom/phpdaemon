<?php

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
	public $buffer;
	public $fd;
	public $finished = false;
	public $ready = false;
	public $readLocked = false;
	public $addr;
	private $sending = true;
	private $reading = false;
	public $connected = false;
	public $directInput = false; // do not use prebuffering of incoming data
	public $directOutput = false; // do not use prebuffering of outgoing data
	public $event;
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

		$this->onWriteOnce = new SplStack();
		
	}
	
	public function onInheritanceFromRequest($req) {
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
		if ($this->directInput || $this->directOutput) {
			$ev = event_new();
			$flags = 0;
			if ($this->directInput) {
				$flags |= EV_READ;
			}
			if ($this->directOutput) {
				$flags |= EV_WRITE;
			}
			if ($this->timeout !== null) {
				$flags |= EV_TIMEOUT;
			}
			event_set($ev, $this->fd, $flags | EV_PERSIST, array($this, 'onDirectEvent'));
			event_base_set($ev, Daemon::$process->eventBase);
			if ($this->priority !== null) {
				event_priority_set($ev, $this->priority);
			}
			if ($this->timeout !== null) {
				event_add($ev, 1e6 * $this->timeout);
			} else {
				event_add($ev);
			}
			$this->event = $ev;
		}
		if (!$this->directOutput || !$this->directOutput) {
			$this->buffer = event_buffer_new(
					$this->fd,
					$this->directInput ? null : array($this, 'onReadEvent'),
					$this->directOutput ? null : array($this, 'onWriteEvent'),
					array($this, 'onFailureEvent')
			);
			event_buffer_base_set($this->buffer, Daemon::$process->eventBase);
			if ($this->priority !== null) {
				event_buffer_priority_set($this->buffer, $this->priority);
			}
			if ($this->timeout !== null) {
				event_buffer_timeout_set($this->buffer, $this->timeout, $this->timeout);
			}
			if (!$this->directInput) {
				event_buffer_watermark_set($this->buffer, EV_READ, $this->lowMark, $this->highMark);
			}
			event_buffer_enable($this->buffer, EV_WRITE | EV_TIMEOUT | EV_PERSIST);
		}
		if (!$this->inited) {
			$this->inited = true;
			$this->init();
		}
	}

	public function onDirectEvent($fd, $events, $arg) {
		if (($events | EV_READ) === $events) {
			$this->onReadEvent($fd);
		}
		if (($events | EV_WRITE) === $events) {
			$this->onWriteEvent($fd);
		}
		if (($events | EV_TIMEOUT) === $events) {
			$this->onFailureEvent($fd);
		}
	}
	
	public function setPriority($p) {
		$this->priority = $p;

		if ($this->buffer !== null) {
			event_buffer_priority_set($this->buffer, $p);
		}
		if ($this->event !== null) {
			event_priority_set($this->event, $p);
		}
		
	}
	
	public function setWatermark($low = null, $high = null) {
		if ($low != null) {
			$this->lowMark = $low;
		}
		if ($high != null) {
		 	$this->highMark = $high;
		}
		event_buffer_watermark_set($this->buffer, EV_READ, $this->lowMark, $this->highMark);
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
	public function write($s) {
		if (!$this->alive) {
			Daemon::log('Attempt to write to dead IOStream ('.get_class($this).')');
			return false;
		}
		if (!$this->buffer) {
			return false;
		}
		if (!strlen($s)) {
			return true;
		}
 		$this->sending = true;
		event_buffer_write($this->buffer, $s);
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
	public function stdin($buf) {
		$this->buf .= $buf;
	}
	
	/**
	 * Close the connection
	 * @param integer Connection's ID
	 * @return void
	 */
	public function close() {
		if (!isset($this->buffer)) {
			return;
		}
		if (isset($this->event)) {
			event_del($this->event);
			event_free($this->event);
			$this->event = null;
		}
		event_buffer_free($this->buffer);
		$this->buffer = null;
		if (isset($this->fd)) {
			$this->closeFd();
		}
	}
	
	public function closeFd() {
		fclose($this->fd);
		$this->closed = true;
	}
	
	/**
	 * Called when the connection has got new data
	 * @param resource Descriptor
	 * @param mixed Optional. Attached variable
	 * @return void
	 */
	public function onReadEvent($stream, $arg = null) {
		if ($this->readLocked) {
			return;
		}
		if (isset($this->onRead)) {
			$this->reading = !call_user_func($this->onRead);
		} else {
			$this->reading = !$this->onRead();
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
	 * @param resource Descriptor
	 * @param mixed Attached variable
	 * @return void
	 */
	public function onWriteEvent($stream, $arg = null) {
		$this->sending = false;
		if ($this->finished) {
			$this->close();
			return;
		}
		if (!$this->ready) {
			$this->ready = true;
			while (!$this->onWriteOnce->isEmpty()) {
				call_user_func($this->onWriteOnce->pop(), $this);
				if (!$this->ready) {
					return;
				}
			}
			$this->alive = true;
			event_buffer_enable($this->buffer, $this->directInput ? (EV_WRITE | EV_TIMEOUT | EV_PERSIST) : (EV_READ | EV_WRITE | EV_TIMEOUT | EV_PERSIST));
			$this->onReady();
		} else {
			while (!$this->onWriteOnce->isEmpty()) {
				call_user_func($this->onWriteOnce->pop(), $this);
			}
		}
		if (isset($this->onWrite)) {
			call_user_func($this->onWrite, $this);
		} else {
			$this->onWrite();
		}
	}
	
	/**
	 * Called when the connection failed
	 * @param resource Descriptor
	 * @param mixed Attached variable
	 * @return void
	 */
	public function onFailureEvent($stream, $arg = null) {
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
	}
	
	/**
	 * Read data from the connection's buffer
	 * @param integer Max. number of bytes to read
	 * @return string Readed data
	 */
	public function read($n) {
	}
	
	public function __destruct() {
		$this->close();
	}
}
