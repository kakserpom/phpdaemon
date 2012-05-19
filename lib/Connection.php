<?php

/**
 * Connection
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Connection {

	public $buf = '';
	public $connId;
	public $EOL = "\n";

	public $readPacketSize  = 4096;
	public $buffer;
	public $resource;
	public $finished = false;
	public $readLocked = false;
	public $addr;
	private $sending = false;
	private $reading = false;
	private $queuedReading = false;
	public $directInput = false; // do not use prebuffering of incoming data
	public $readEvent;
	protected $initialLowMark  = 1;         // initial value of the minimal amout of bytes in buffer
	protected $initialHighMark = 0xFFFF;  	// initial value of the maximum amout of bytes in buffer
	/**
	 * SocketSession constructor
	 * @param integer Connection's ID
	 * @param object AppInstance
	 * @return void
	 */
	public function __construct($connId, $resource, $addr, $pool = null) {
		$this->connId = $connId;
		$this->resource = $resource;
		$this->pool = $pool;
		$this->addr = $addr;
		
		if ($this->directInput) {
			$ev = event_new();
			if (!event_set($ev, $resource, EV_READ | EV_PERSIST, array($this, 'onReadEvent'))) {
				Daemon::log(get_class($this) . '::' . __METHOD__ . ': Couldn\'t set event on accepted socket #' . $connId);
				return;
			}
			event_base_set($ev, Daemon::$process->eventBase);
			event_add($ev);
			$this->readEvent = $ev;
		}
		$buf = event_buffer_new(
			$resource,
			$this->directInput ? NULL : array($this, 'onReadEvent'),
			array($this, 'onWriteEvent'),
			array($this, 'onFailureEvent'),
			array($connId)
		);
		if (!event_buffer_base_set($buf, Daemon::$process->eventBase)) {
			throw new Exception('Couldn\'t set base of buffer.');
		}
		event_buffer_priority_set($buf, 10);
		event_buffer_watermark_set($buf, EV_READ, $this->initialLowMark, $this->initialHighMark);
		event_buffer_enable($buf, $this->directInput ? (EV_WRITE | EV_PERSIST) : (EV_READ | EV_WRITE | EV_PERSIST));
		$this->buffer = $buf;
		$this->init();
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
		$this->appInstance->onReadEvent(null, array($this->connId));
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
 		$this->sending = true;
		return event_buffer_write($this->buffer, $s);		
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
		unset($this->pool->storage[$this->connId]);
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
		unset($this->pool->storage[$this->connId]);
		if (!isset($this->buffer)) {
			return;
		}
		if (isset($this->readEvent)) {
			event_del($this->readEvent);
			event_free($this->readEvent);
			$this->readEvent = null;
		}
		event_buffer_free($this->buffer);
		if (isset($this->resource)) {
			if (Daemon::$useSockets) {
				socket_close($this->resource);
			} else {
				fclose($this->resource);
			}
		}
	}
	
	/**
	 * Called when the connection has got new data
	 * @param resource Descriptor
	 * @param mixed Attacted variable
	 * @return void
	 */
	public function onReadEvent($stream, $arg) {
		if ($this->queuedReading) {
			$this->reading = true;
			Daemon_TimedEvent::setTimeout('readPoolEvent');
		}
		if ($this->readLocked) {
			return;
		}
		while (($buf = $this->read($this->readPacketSize)) !== false) {
			$this->stdin($buf);
		}
	}
	
	/**
	 * Called when the connection is ready to accept new data
	 * @param resource Descriptor
	 * @param mixed Attacted variable
	 * @return void
	 */
	public function onWriteEvent($stream, $arg) {
		$this->sending = false;
		if ($this->finished) {
			$this->close();
		}
		$this->onWrite();
	}
	
	/**
	 * Called when the connection failed
	 * @param resource Descriptor
	 * @param mixed Attacted variable
	 * @return void
	 */
	public function onFailureEvent($stream, $arg) {
		$this->close();
		if ($this->finished) {
			return;
		}
		$this->finished = true;
		$this->onFinish();
		
		event_base_loopexit(Daemon::$process->eventBase);
	}
	
	/**
	 * Read data from the connection's buffer
	 * @param integer Max. number of bytes to read
	 * @return string Readed data
	 */
	public function read($n) {
		if (!isset($this->buffer)) {
			return false;
		}
		
		if (isset($this->readEvent)) {
			if (Daemon::$useSockets) {
				$read = socket_read($this->resource, $n);

				if ($read === false) {
					$no = socket_last_error($this->resource);

					if ($no !== 11) {  // Resource temporarily unavailable
						Daemon::log(get_class($this) . '::' . __METHOD__ . ': connId = ' . $connId . '. Socket error. (' . $no . '): ' . socket_strerror($no));
						$this->onFailureEvent($connId, array());
					}
				}
			} else {
				$read = fread($this->resource, $n);
			}
		} else {
			$read = event_buffer_read($this->buffer, $n);
		}
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
}
