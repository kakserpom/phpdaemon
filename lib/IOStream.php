<?php
/**
 * IOStream
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
abstract class IOStream {
	public $EOL = "\n";
	public $EOLS;

	public $listenerMode = false;
	public $readPacketSize  = 8192;
	public $bev;
	public $fd;
	public $finished = false;
	public $ready = false;
	public $writing = true;
	public $reading = false;
	protected $lowMark  = 1;         // initial value of the minimal amout of bytes in buffer
	protected $highMark = 0xFFFF;  	// initial value of the maximum amout of bytes in buffer
	public $priority;
	public $inited = false;
	public $state = 0;             // stream state of the connection (application protocol level)
	const STATE_ROOT = 0;
	const STATE_STANDBY = 0;
	public $onWriteOnce;
	public $timeout = null;
	public $url;
	public $alive = false; // alive?
	public $pool;
	public $bevConnect = false;
	public $wRead = false;
	public $freed = false;

	/**
	 * IOStream constructor
 	 * @param resource File descriptor. Optional.
	 * @param object Pool. Optional.
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

		if ($this->EOL === "\n") {
			$this->EOLS = EventBuffer::EOL_LF;
		}
		elseif ($this->EOL === "\r\n") {
			$this->EOLS = EventBuffer::EOL_CRLF;
		} else {
			$this->EOLS = EventBuffer::EOL_ANY;	
		}

		$this->onWriteOnce = new StackCallbacks;
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

	public function setFd($fd) {
		$this->fd = $fd;
		$class = get_class($this);
		if ($this->fd === false) {
			$this->finish();
			return;
		}
		$this->bev = new EventBufferEvent(Daemon::$process->eventBase, $this->fd, 
			!is_resource($this->fd) ? EventBufferEvent::OPT_CLOSE_ON_FREE : 0 /*| EventBufferEvent::OPT_DEFER_CALLBACKS /* buggy option */,
			[$this, 'onReadEv'], [$this, 'onWriteEv'], [$this, 'onStateEv']
		);
		if (!$this->bev) {
			return;
		}
		if ($this->priority !== null) {
			$this->bev->priority = $this->priority;
		}
		if ($this->timeout !== null) {
			$this->bev->setTimeouts($this->timeout, $this->timeout);
		}
		$this->bev->setWatermark(Event::READ, $this->lowMark, $this->highMark);
		if (!$this->bev->enable(Event::READ | Event::WRITE | Event::TIMEOUT | Event::PERSIST)) {
			Daemon::log(get_class($this). ' enable() returned false');
		}
		if ($this->bevConnect && ($this->fd === null)) {
			//$this->bev->connect($this->addr, false);
			$this->bev->connectHost(Daemon::$process->dnsBase, $this->hostReal, $this->port, EventUtil::AF_UNSPEC);
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
				$this->bev->setTimeout($this->timeout, $this->timeout);
			}
		}
	}
	
	public function setPriority($p) {
		$this->priority = $p;
		$this->bev->priority = $p;
	}
	
	public function setWatermark($low = null, $high = null) {
		if ($low != null) {
			$this->lowMark = $low;
		}
		if ($high != null) {
		 	$this->highMark = $high;
		}
		$this->bev->setWatermark(Event::READ, $this->lowMark, $this->highMark);
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
	public function gets() { // @TODO: deprecate in favor of readln
		$p = strpos($this->buf, $this->EOL);

		if ($p === false) {
			return false;
		}

		$sEOL = strlen($this->EOL);
		$r = binarySubstr($this->buf, 0, $p + $sEOL);
		$this->buf = binarySubstr($this->buf, $p + $sEOL);

		return $r;
	}

	public function readLine($eol = null) {
		if (!isset($this->bev)) {
			return null;
		}
		return $this->bev->input->readLine($eol ?: $this->EOLS);
	}

	public function drainIfMatch($str) {
		if (!isset($this->bev)) {
			return false;
		}
		$l = strlen($str);
		$ll = $this->bev->input->length;
		if ($ll < $l) {
			$read = $this->read($ll);
			return strncmp($read, $str, $ll);
		}
		$read = $this->read($l);
		if ($read === $str) {
			return true;
		}
		$this->bev->input->prepend($read);
		return false;
	}

	public function lookExact($n) {
		if (!isset($this->bev)) {
			return false;
		}
		if ($this->bev->input->length < $n) {
			return false;
		}
		$data = $this->read($n);
		$this->bev->input->prepend($data);
		return $data;
	}

	public function prependInput($str) {
		if (!isset($this->bev)) {
			return false;
		}
		return $this->bev->input->prepend($str);
	}

	public function prependOutput($str) {
		if (!isset($this->bev)) {
			return false;
		}
		return $this->bev->output->prepend($str);
	}

	public function look($n) {
		$data = $this->read($n);
		$this->bev->input->prepend($data);
		return $data;
	}

	/*public function search($what, $start = null, $end = null) {
		// @TODO: cache of EventBufferPosition
		if (is_integer($start)) {
			$s = $start;
			$start = new EventBufferPosition;
		}
		return $this->bev->input->search($what, $start, $end);

	}*/

	public function readFromBufExact($n) { // @TODO: deprecate
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

	public function readExact($n) {
		if ($n === 0) {
			return '';
		}
		if ($this->bev->input->length < $n) {
			return false;
		} else {
			return $this->read($n);
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
	 * Freeze input
	 * @param boolean At front. Default is true. If the front of a buffer is frozen, operations that drain data from the front of the buffer, or that prepend data to the buffer, will fail until it is unfrozen. If the back a buffer is frozen, operations that append data from the buffer will fail until it is unfrozen.
	 * @return void
	 */
	public function freezeInput($at_front = false) {
		if (isset($this->bev)) {
			return $this->bev->input->freeze($at_front);
		}
		return false;
	}

	/**
	 * Unfreeze input
	 * @param boolean At front. Default is true. If the front of a buffer is frozen, operations that drain data from the front of the buffer, or that prepend data to the buffer, will fail until it is unfrozen. If the back a buffer is frozen, operations that append data from the buffer will fail until it is unfrozen.
	 * @return void
	 */
	public function unfreezeInput($at_front = false) {
		if (isset($this->bev)) {
			return $this->bev->input->unfreeze($at_front);
		}
		return false;
	}

	/**
	 * Freeze output
	 * @param boolean At front. Default is true. If the front of a buffer is frozen, operations that drain data from the front of the buffer, or that prepend data to the buffer, will fail until it is unfrozen. If the back a buffer is frozen, operations that append data from the buffer will fail until it is unfrozen.
	 * @return void
	 */
	public function freezeOutput($at_front = true) {
		if (isset($this->bev)) {
			return $this->bev->output->unfreeze($at_front);
		}
		return false;
	}

	/**
	 * Unfreeze output
	 * @param boolean At front. Default is true. If the front of a buffer is frozen, operations that drain data from the front of the buffer, or that prepend data to the buffer, will fail until it is unfrozen. If the back a buffer is frozen, operations that append data from the buffer will fail until it is unfrozen.
	 * @return void
	 */
	public function unfreezeOutput($at_front = true) {
		if (isset($this->bev)) {
			return $this->bev->output->unfreeze($at_front);
		}
		return false;
	}

	/**
	 * Called when the connection is ready to accept new data
	 * @todo protected?
	 * @return void
	 */
	public function onWrite() {}

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
 		$this->writing = true;
 		Daemon::$noError = true;
		if (!$this->bev->write($data) || !Daemon::$noError) {
			$this->close();
		}
		return true;
	}

	/**
	 * Send data and appending \n to connection. Note that it just writes to buffer flushed at every baseloop
	 * @param string Data to send.
	 * @return boolean Success.
	 */
	public function writeln($data) {
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
 		$this->writing = true;
		$this->bev->write($data);
		$this->bev->write($this->EOL);
		return true;
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
		/// 
	
		// if (!Daemon::$process->eventBase->gotStop())
		Daemon::$process->eventBase->stop();
		$this->onFinish();
		if (!$this->writing) {
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
	public function stdin($buf) {} // @TODO: deprecate
	
	/**
	 * Close the connection
	 * @param integer Connection's ID
	 * @return void
	 */
	public function close() {
		if (!$this->freed) {
			$this->freed = true;
			//Daemon::log(get_class($this) . '-> free(' . spl_object_hash($this) . ')');
			$this->bev->free();
			$this->bev = null;
			if (is_resource($this->fd)) {
				socket_close($this->fd);
			}
			//Daemon::$process->eventBase->stop();
		}
		if ($this->pool) {
			$this->pool->detach($this);
		}
	}
	
	/**
	 * Called when the connection has got new data
	 * @param resource Bufferevent
	 * @return void
	 */
	public function onReadEv($bev) {
		if (!$this->ready) {
			$this->wRead = true;
			return;
		}
		try {
			$this->reading = !$this->onRead();
		} catch (Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
	}
	
	public function onRead() {
		while (($buf = $this->read($this->readPacketSize)) !== false) {
			$this->stdin($buf);
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
		if (!$this->writing) {
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
	public function onWriteEv($bev) {
		$this->writing = false;
		if ($this->finished) {
			$this->close();
			return;
		}
		if (!$this->ready) {
			$this->ready = true;
			while (!$this->onWriteOnce->isEmpty()) {
				try {
					$this->onWriteOnce->executeOne($this);
				} catch (Exception $e) {
					Daemon::uncaughtExceptionHandler($e);
				}
				if (!$this->ready) {
					return;
				}
			}
			$this->alive = true;
			/*if (isset($this->bev)) {
				if (!$this->bev->enable(Event::READ)) {
					Daemon::log(get_class($this). ' second enable() returned false');
				}
			}*/
			try {			
				$this->onReady();
				if ($this->wRead) {
					$this->wRead = false;
					$this->onRead();
				}
			} catch (Exception $e) {
				Daemon::uncaughtExceptionHandler($e);
			}
		} else {
			$this->onWriteOnce->executeAll($this);
		}
		try {
			$this->onWrite();
		} catch (Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
	}
	
	/**
	 * Called when the connection state changed
	 * @param resource Bufferevent
	 * @param int Events
	 * @param mixed Attached variable
	 * @return void
	 */
	public function onStateEv($bev, $events) {
		if ($events & EventBufferEvent::CONNECTED) {
			$this->onWriteEv($bev);
		} elseif ($events & (EventBufferEvent::ERROR | EventBufferEvent::EOF)) {
			try {
				if ($this->finished) {
					return;
				}
				if ($events & EventBufferEvent::ERROR) {
					trigger_error("Socket error #"
						.EventUtil::getLastSocketErrno()
						.":".EventUtil::getLastSocketError(), E_USER_WARNING);
				}
				$this->finished = true;
				$this->onFinish();
				$this->close();
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
		if (!$this->bev instanceof EventBufferEvent) {
			return false;
		}
		$r = $this->bev->read($read, $n);
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
