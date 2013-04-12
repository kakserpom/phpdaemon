<?php
/**
 * IOStream
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
abstract class IOStream {

	/**
	 * Associated pool
	 * @var object ConnectionPool
	 */	
	public $pool;

	/**
	 * EOL
	 * @var string "\n"
	 */	
	protected $EOL = "\n";

	/**
	 * EOLS_* switch
	 * @var integer
	 */	
	protected $EOLS;

	/**
	 * Number of bytes on each read() in default onRead() implementation
	 * @deprecated Remove in 1.0 or earlier
	 * @var integer 8192
	 */	
	protected $readPacketSize  = 8192;

	/**
	 * EventBufferEvent
	 * @var object EventBufferEvent
	 */	
	protected $bev;

	/**
	 * File descriptor
	 * @var resource|integer
	 */	
	protected $fd;

	/**
	 * Finished?
	 * @var boolean
	 */	
	protected $finished = false;

	/**
	 * Ready? 
	 * @var boolean
	 */	
	protected $ready = false;

	/**
	 * Writing?
	 * @var boolean
	 */	
	protected $writing = true;

	/**
	 * Default low mark. Minimum number of bytes in buffer.
	 * @var integer
	 */
	protected $lowMark  = 1;

	/**
	 * Default high mark. Maximum number of bytes in buffer.
	 * @var integer
	 */
	protected $highMark = 0xFFFF;  	// initial value of the maximum amout of bytes in buffer

	/**
	 * Priority
	 * @var integer
	 */
	protected $priority;

	/**
	 * Initialized?
	 * @var boolean
	 */	
	protected $inited = false;

	/**
	 * Current state
	 * @var integer
	 */	
	protected $state = 0;             // stream state of the connection (application protocol level)
	const STATE_ROOT = 0;
	const STATE_STANDBY = 0;

	/**
	 * Stack of callbacks called when writing is done
	 * @var object StackCallbacks
	 */
	protected $onWriteOnce;

	/**
	 * Timeout
	 * @var integer
	 */	
	protected $timeout = null;

	/**
	 * URL
	 * @var string
	 */	
	protected $url;

	/**
	 * Alive?
	 * @var boolean
	 */	
	protected $alive = false;

	/**
	 * Is bevConnect used?
	 * @var boolean
	 */	
	protected $bevConnect = false;

	/**
	 * Should we can onReadEv() in next onWriteEv()?
	 * @var boolean
	 */	
	protected $wRead = false;

	/**
	 * Freed?
	 * @var boolean
	 */	
	protected $freed = false;

	/**
	 * Context
	 * @var object
	 */
	protected $ctx;

	/**
	 * Context name
	 * @var object
	 */
	protected $ctxname;

	/**
	 * Defines context-related flag
	 * @var integer
	 */
	protected $ctxMode;

	/**
	 * SSL?
	 * @var boolean
	 */
	protected $ssl = false;

	/**
	 * IOStream constructor
 	 * @param resource File descriptor. Optional.
	 * @param object Pool. Optional.
	 * @return void
	 */
	public function __construct($fd = null, $pool = null) {
		if ($pool) {
			$this->pool = $pool;
			$this->pool->attach($this);
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
	 * Freed?
	 * @return boolean
	 */	
	public function isFreed() {
		return $this->freed;
	}

	/**
	 * Finished?
	 * @return boolean
	 */	
	public function isFinished() {
		return $this->finished;
	}

	/**
	 * Get EventBufferEvent
	 * @return  EventBufferEvent
	 */
	public function getBev() {
		return $this->bev;
	}

	/**
	 * Get file descriptor
	 * @return mixed File descriptor
	 */	
	public function getFd() {
		return $this->fd;
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
	 * Sets context mode
	 * @param object Context
	 * @param integer Mode
	 * @return void
	 */

	public function setContext($ctx, $mode) {
		$this->ctx = $ctx;
		$this->ctxMode = $mode;
	}

	/**
	 * Sets fd
	 * @param mixed File descriptor
	 * @param [object EventBufferEvent]
	 * @return void
	 */	

	public function setFd($fd, $bev = null) {
		$this->fd = $fd;
		if ($this->fd === false) {
			$this->finish();
			return;
		}
		if ($bev !== null) {
			$this->bev = $bev;
			$this->bev->setCallbacks([$this, 'onReadEv'], [$this, 'onWriteEv'], [$this, 'onStateEv']);
			if (!$this->bev) {
				return;
			}
			$this->ready = true;
			$this->alive = true;
		} else {
			$flags = !is_resource($this->fd) ? EventBufferEvent::OPT_CLOSE_ON_FREE : 0;
			$flags |= EventBufferEvent::OPT_DEFER_CALLBACKS; /* buggy option */
			if ($this->ctx) {
				if ($this->ctx instanceof EventSslContext) {
					$this->bev = EventBufferEvent::sslSocket(Daemon::$process->eventBase, $this->fd, $this->ctx, $this->ctxMode, $flags);
					if ($this->bev) {
						$this->bev->setCallbacks([$this, 'onReadEv'], [$this, 'onWriteEv'], [$this, 'onStateEv']);
					}
					$this->ssl = true;
				} else {
					$this->log('unsupported type of context: '.($this->ctx ? get_class($this->ctx) : 'undefined'));
					return;
				}
			} else {
				$this->bev = new EventBufferEvent(Daemon::$process->eventBase, $this->fd, $flags, [$this, 'onReadEv'], [$this, 'onWriteEv'], [$this, 'onStateEv']);
			}
			if (!$this->bev) {
				return;
			}
		}
		if ($this->priority !== null) {
			$this->bev->priority = $this->priority;
		}
		if ($this->timeout !== null) {
			$this->setTimeout($this->timeout);
		}
		if ($this->bevConnect && ($this->fd === null)) {
			//$this->bev->connectHost(Daemon::$process->dnsBase, $this->hostReal, $this->port);
			$this->bev->connect($this->addr);
		}
		if (!$this->bev) {
			$this->finish();
			return;
		}
		if (!$this->bev->enable(Event::READ | Event::WRITE | Event::TIMEOUT | Event::PERSIST)) {
			$this->finish();
			return;
		}
		$this->bev->setWatermark(Event::READ, $this->lowMark, $this->highMark);
		init:
		if ($this->keepalive) {
			$this->setKeepalive(true);
		}
		if (!$this->inited) {
			$this->inited = true;
			$this->init();
		}
	}

	/**
	 * Set timeout
	 * @param integer Timeout
	 * @return void
	 */
	public function setTimeout($rw) {
		$this->setTimeouts($rw, $rw);
	}

	/**
	 * Set timeouts
	 * @param integer Read timeout in seconds
	 * @param integer Write timeout in seconds
	 * @return void
	 */
	public function setTimeouts($read, $write) {
		$this->timeoutRead = $read;
		$this->timeoutWrite = $write;
		if ($this->bev) {
			$this->bev->setTimeouts($this->timeoutRead, $this->timeoutWrite);
		}
	}
	
	/* Sets priority
	 * @param integer Priority
	 * @return void
	 */
	public function setPriority($p) {
		$this->priority = $p;
		$this->bev->priority = $p;
	}
	
	/* Sets watermark
	 * @param integer|null Low
	 * @param integer|null High
	 * @return void
	 */
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
	 * @return void
	 */
	protected function init() {}

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

	/* Reads line from buffer
	 * @param [integer EOLS_*]
	 * @return string|null
	 */
	public function readLine($eol = null) {
		if (!isset($this->bev)) {
			return null;
		}
		return $this->bev->input->readLine($eol ?: $this->EOLS);
	}

	/* Drains buffer
	 * @param integer Numbers of bytes to drain
	 * @return boolean Success
	 */
	public function drain($n) {
		return $this->bev->input->drain($n);
	}

	/* Drains buffer it matches the string
	 * @param string Data
	 * @return boolean|null Success
	 */
	public function drainIfMatch($str) {
		if (!isset($this->bev)) {
			return false;
		}
		$in = $this->bev->input;
		$l = strlen($str);
		$ll = $in->length;
		if ($ll === 0) {
			return $l === 0 ? true : null;
		}
		if ($ll < $l) {
			return $in->search(substr($str, 0, $ll)) === 0 ? null : false;
		}
		if ($ll === $l) {
			if ($in->search($str) === 0) {
				$in->drain($l);
				return true;
			}
		}
		elseif ($in->search($str, 0, $l) === 0) {
			$in->drain($l);
			return true;
		}
		return false;
	}


	/* Reads exact $n bytes of buffer without draining
	 * @param integer Number of bytes to read
	 * @return string|false
	 */
	public function lookExact($n, $o = 0) {
		if (!isset($this->bev)) {
			return false;
		}
		$data = $this->bev->input->substr($o, $n);
		if (strlen($data) < $n) {
			return false;
		}
		return $data;
	}


	/* Prepends data to input buffer
	 * @param string Data
	 * @return boolean Success
	 */
	public function prependInput($str) {
		if (!isset($this->bev)) {
			return false;
		}
		return $this->bev->input->prepend($str);
	}

	/* Prepends data to output buffer
	 * @param string Data
	 * @return boolean Success
	 */
	public function prependOutput($str) {
		if (!isset($this->bev)) {
			return false;
		}
		return $this->bev->output->prepend($str);
	}

	/* Read from buffer without draining
	 * @param integer Number of bytes to read
	 * @param integer [Offset
	 * @return string|false
	 */
	public function look($n, $o = 0) {
		if (!isset($this->bev)) {
			return false;
		}
		return $this->bev->input->substr($o, $n);
	}

	/* Read from buffer without draining
	 * @param Offset
	 * @param [integer Number of bytes to read
	 * @return string|false
	 */
	public function substr($o, $n = -1) {
		if (!isset($this->bev)) {
			return false;
		}
		$this->bev->input->substr($o, $n);
		return $data;
	}

	/* Searches first occurence of the string in input buffer
	 * @param string Needle
	 * @param [integer Offset start]
	 * @param [integer Offset end]
	 * @return integer Position
	 */
	public function search($what, $start = 0, $end = -1) {
		return $this->bev->input->search($what, $start, $end);
	}

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

	/* Reads exact $n bytes from buffer
	 * @param integer Number of bytes to read
	 * @return string|false
	 */

	public function readExact($n) {
		if ($n === 0) {
			return '';
		}
		if ($this->bev->input->length < $n) {
			return false;
		}
		return $this->read($n);
	}

	/*
	 * Returns length of input buffer
	 * @return integer
	 */
	public function getInputLength() {
		return $this->bev->input->length;
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
	 * @return boolean Success
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
	 * @return boolean Success
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
	 * @return boolean Success
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
	 * @return boolean Success
	 */
	public function unfreezeOutput($at_front = true) {
		if (isset($this->bev)) {
			return $this->bev->output->unfreeze($at_front);
		}
		return false;
	}

	/**
	 * Called when the connection is ready to accept new data
	 * @return void
	 */
	protected function onWrite() {}


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
		if (!strlen($data) && !strlen($this->EOL)) {
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
		Daemon::$process->eventBase->stop();
		$this->onFinish();
		if (!$this->writing) {
			$this->close();
		}
	}

	/**
	 * Called when the session finished
	 * @return void
	 */
	protected function onFinish() {
	}

	/**
	 * Called when new data received
	 * @param string New received data
	 * @return void
	 */
	protected function stdin($buf) {} // @TODO: deprecated, remove in 1.0
	
	/**
	 * Close the connection
	 * @param integer Connection's ID
	 * @return void
	 */
	public function close() {
		if (!$this->freed) {
			$this->freed = true;
			if (isset($this->bev)) {
				$this->bev->free();
			}
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
	 * Unsets pointers of associated EventBufferEvent and File descriptr
	 * @return void
	 */
	public function unsetFd() {
		$this->bev = null;
		$this->fd = null;
	}

	protected function log($m) {
		Daemon::log(get_class($this).': '.$m);
	}
	
	/**
	 * Called when the connection has got new data
	 * @param resource Bufferevent
	 * @return void
	 */
	public function onReadEv($bev) {
		if (Daemon::$config->logevents->value) {
			$this->log(' onReadEv called');
		}
		if (!$this->ready) {
			$this->wRead = true;
			return;
		}
		if ($this->finished) {
			return;
		}
		try {
			$this->onRead();
		} catch (Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
	}

	/**
	 * Called when new data received
	 * @return boolean
	 */
	protected function onRead() { // @todo: remove this default implementation in 1.0
		while (($buf = $this->read($this->readPacketSize)) !== false) {
			$this->stdin($buf);
		}
	}
	
	/**
	 * Called when the stream is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	protected function onReady() {
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
		if (Daemon::$config->logevents->value) {
			Daemon::log(get_class().' onWriteEv called');
		}
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
					$errno = EventUtil::getLastSocketErrno();
					if ($errno !== 0) {
						trigger_error('Socket error #' . $errno . ':' . EventUtil::getLastSocketError(), E_USER_NOTICE);
					}
					if ($this->ssl && $this->bev) {
						while ($err = $this->bev->sslError()) {
							trigger_error('EventBufferEvent SSL error: ' . $err . PHP_EOL, E_USER_NOTICE);
						}
					}
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
	 * Moves arbitrary number of bytes from input buffer to given buffer
	 * @param EventBuffer Destination nuffer
	 * @param integer Max. number of bytes to move
	 * @return integer 
	 */
	public function moveToBuffer(EventBuffer $dest, $n) {
		if (!isset($this->bev)) {
			return false;
		}
		return $dest->appendFrom($this->bev->input, $n);
	}

	/**
	 * Moves arbitrary number of bytes from given buffer to output buffer
	 * @param EventBuffer Source buffer
	 * @param integer Max. number of bytes to move
	 * @return integer 
	 */
	public function writeFromBuffer(EventBuffer $src, $n) {
		if (!isset($this->bev)) {
			return false;
		}
		return $this->bev->output->appendFrom($src, $n);
	}

	/**
	 * Read data from the connection's buffer
	 * @param integer Max. number of bytes to read
	 * @return string Readed data
	 */
	public function read($n) {
		if (!isset($this->bev)) {
			return false;
		}
		$read = $this->bev->read($n);
		if ($read === null) {
			return false;
		}
		return $read;
	}
}
