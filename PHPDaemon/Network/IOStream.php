<?php
namespace PHPDaemon\Network;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\FS\File;
use PHPDaemon\Structures\StackCallbacks;

/**
 * IOStream
 * @package PHPDaemon\Network
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
abstract class IOStream {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;
	use \PHPDaemon\Traits\EventHandlers;

	/**
	 * @var object Associated pool
	 */
	public $pool;

	/**
	 * @var string EOL
	 */
	protected $EOL = "\n";

	/**
	 * @var integer EOLS_* switch
	 */
	protected $EOLS;

	/**
	 * @var object EventBufferEvent
	 */
	protected $bev;

	/**
	 * @var resource File descriptor
	 */
	protected $fd;

	/**
	 * @var boolean Finished?
	 */
	protected $finished = false;

	/**
	 * @var boolean Ready?
	 */
	protected $ready = false;

	/**
	 * @var boolean Writing?
	 */
	protected $writing = true;

	/**
	 * @var integer Default low mark. Minimum number of bytes in buffer
	 */
	protected $lowMark = 1;

	/**
	 * @var integer Default high mark. Maximum number of bytes in buffer
	 */
	protected $highMark = 0xFFFF; // initial value of the maximum amout of bytes in buffer

	/**
	 * @var integer Priority
	 */
	protected $priority;

	/**
	 * @var boolean Initialized?
	 */
	protected $inited = false;

	/**
	 * @var integer Current state
	 */
	protected $state = 0; // stream state of the connection (application protocol level)

	/**
	 * Alias of STATE_STANDBY
	 */
	const STATE_ROOT = 0;

	/**
	 * Standby state (default state)
	 */
	const STATE_STANDBY = 0;

	/**
	 * @var object Stack of callbacks called when writing is done
	 */
	protected $onWriteOnce;

	/**
	 * @var integer Timeout
	 */
	protected $timeout = null;

	/**
	 * @var string URL
	 */
	protected $url;

	/**
	 * @var boolean Alive?
	 */
	protected $alive = false;

	/**
	 * @var boolean Is bevConnect used?
	 */
	protected $bevConnect = false;

	/**
	 * @var boolean Should we can onReadEv() in next onWriteEv()?
	 */
	protected $wRead = false;

	/**
	 * @var boolean Freed?
	 */
	protected $freed = false;

	/**
	 * @var object Context
	 */
	protected $ctx;

	/**
	 * @var object Context name
	 */
	protected $ctxname;

	/**
	 * @var integer Defines context-related flag
	 */
	protected $ctxMode;

	/**
	 * @var boolean SSL?
	 */
	protected $ssl = false;

	/**
	 * @var float Read timeout
	 */
	protected $timeoutRead;

	/**
	 * @var float Write timeout
	 */
	protected $timeoutWrite;

	/**
	 * IOStream constructor
	 * @param resource $fd   File descriptor. Optional
	 * @param object   $pool Pool. Optional
	 */
	public function __construct($fd = null, $pool = null) {
		if ($pool) {
			$this->pool = $pool;
			$this->pool->attach($this);
			if (isset($this->pool->config->timeout->value)) {
				$this->timeout = $this->pool->config->timeout->value;
			}
			if (isset($this->pool->config->timeoutread->value)) {
				$this->timeoutRead = $this->pool->config->timeoutread->value;
			}
			if (isset($this->pool->config->timeoutwrite->value)) {
				$this->timeoutWrite = $this->pool->config->timeoutwrite->value;
			}
		}

		if ($fd !== null) {
			$this->setFd($fd);
		}

		if ($this->EOL === "\n") {
			$this->EOLS = \EventBuffer::EOL_LF;
		}
		elseif ($this->EOL === "\r\n") {
			$this->EOLS = \EventBuffer::EOL_CRLF;
		}
		else {
			$this->EOLS = \EventBuffer::EOL_ANY;
		}

		$this->onWriteOnce = new StackCallbacks;
	}

	/**
	 * Getter
	 * @param  string $name Name
	 * @return mixed
	 */
	public function __get($name) {
		if (   $name === 'finished'
			|| $name === 'alive'
			|| $name === 'freed'
			|| $name === 'url'
		) {
			return $this->{$name};
		}
		return NULL;
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
	 * @return EventBufferEvent
	 */
	public function getBev() {
		return $this->bev;
	}

	/**
	 * Get file descriptor
	 * @return resource File descriptor
	 */
	public function getFd() {
		return $this->fd;
	}

	/**
	 * Sets context mode
	 * @param  object  $ctx  Context
	 * @param  integer $mode Mode
	 * @return void
	 */

	public function setContext($ctx, $mode) {
		$this->ctx     = $ctx;
		$this->ctxMode = $mode;
	}

	/**
	 * Sets fd
	 * @param  resource $fd  File descriptor
	 * @param  object   $bev EventBufferEvent
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
		}
		else {
			$flags = !is_resource($this->fd) ? \EventBufferEvent::OPT_CLOSE_ON_FREE : 0;
			$flags |= \EventBufferEvent::OPT_DEFER_CALLBACKS; /* buggy option */
			if ($this->ctx) {
				if ($this->ctx instanceof \EventSslContext) {
					$this->bev = \EventBufferEvent::sslSocket(Daemon::$process->eventBase, $this->fd, $this->ctx, $this->ctxMode, $flags);
					if ($this->bev) {
						$this->bev->setCallbacks([$this, 'onReadEv'], [$this, 'onWriteEv'], [$this, 'onStateEv']);
					}
					$this->ssl = true;
				}
				else {
					$this->log('unsupported type of context: ' . ($this->ctx ? get_class($this->ctx) : 'undefined'));
					return;
				}
			}
			else {
				$this->bev = new \EventBufferEvent(Daemon::$process->eventBase, $this->fd, $flags, [$this, 'onReadEv'], [$this, 'onWriteEv'], [$this, 'onStateEv']);
			}
			if (!$this->bev) {
				return;
			}
		}
		if ($this->priority !== null) {
			$this->bev->priority = $this->priority;
		}
		$this->setTimeouts($this->timeoutRead !== null ? $this->timeoutRead : $this->timeout,
							$this->timeoutWrite!== null ? $this->timeoutWrite : $this->timeout);
		if ($this->bevConnect && ($this->fd === null)) {
			//$this->bev->connectHost(Daemon::$process->dnsBase, $this->hostReal, $this->port);
			$this->bev->connect($this->addr);
		}
		if (!$this->bev) {
			$this->finish();
			return;
		}
		if (!$this->bev->enable(\Event::READ | \Event::WRITE | \Event::TIMEOUT | \Event::PERSIST)) {
			$this->finish();
			return;
		}
		$this->bev->setWatermark(\Event::READ, $this->lowMark, $this->highMark);
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
	 * @param  integer $rw Timeout
	 * @return void
	 */
	public function setTimeout($rw) {
		$this->setTimeouts($rw, $rw);
	}

	/**
	 * Set timeouts
	 * @param  integer $read  Read timeout in seconds
	 * @param  integer $write Write timeout in seconds
	 * @return void
	 */
	public function setTimeouts($read, $write) {
		$this->timeoutRead  = $read;
		$this->timeoutWrite = $write;
		if ($this->bev) {
			$this->bev->setTimeouts($this->timeoutRead, $this->timeoutWrite);
		}
	}

	/**
	 * Sets priority
	 * @param  integer $p Priority
	 * @return void
	 */
	public function setPriority($p) {
		$this->priority      = $p;
		$this->bev->priority = $p;
	}

	/**
	 * Sets watermark
	 * @param  integer|null $low  Low
	 * @param  integer|null $high High
	 * @return void
	 */
	public function setWatermark($low = null, $high = null) {
		if ($low !== null) {
			$this->lowMark = $low;
		}
		if ($high !== null) {
			$this->highMark = $high;
		}
		$this->bev->setWatermark(\Event::READ, $this->lowMark, $this->highMark);
	}

	/**
	 * Called when the session constructed
	 * @return void
	 */
	protected function init() {
	}

	/**
	 * Reads line from buffer
	 * @param  integer     $eol EOLS_*
	 * @return string|null
	 */
	public function readLine($eol = null) {
		if (!isset($this->bev)) {
			return null;
		}
		return $this->bev->input->readLine($eol ? : $this->EOLS);
	}

	/**
	 * Drains buffer
	 * @param  integer $n Numbers of bytes to drain
	 * @return boolean    Success
	 */
	public function drain($n) {
		return $this->bev->input->drain($n);
	}

	/**
	 * Drains buffer it matches the string
	 * @param  string       $str Data
	 * @return boolean|null      Success
	 */
	public function drainIfMatch($str) {
		if (!isset($this->bev)) {
			return false;
		}
		$in = $this->bev->input;
		$l  = strlen($str);
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

	/**
	 * Reads exact $n bytes of buffer without draining
	 * @param  integer $n Number of bytes to read
	 * @param  integer $o Offset
	 * @return string|false
	 */
	public function lookExact($n, $o = 0) {
		if (!isset($this->bev)) {
			return false;
		}
		if ($o + $n > $this->bev->input->length) {
			return false;
		}
		return $this->bev->input->substr($o, $n);
	}

	/**
	 * Prepends data to input buffer
	 * @param  string  $str Data
	 * @return boolean      Success
	 */
	public function prependInput($str) {
		if (!isset($this->bev)) {
			return false;
		}
		return $this->bev->input->prepend($str);
	}

	/**
	 * Prepends data to output buffer
	 * @param  string  $str Data
	 * @return boolean      Success
	 */
	public function prependOutput($str) {
		if (!isset($this->bev)) {
			return false;
		}
		return $this->bev->output->prepend($str);
	}

	/**
	 * Read from buffer without draining
	 * @param integer $n Number of bytes to read
	 * @param integer $o Offset
	 * @return string|false
	 */
	public function look($n, $o = 0) {
		if (!isset($this->bev)) {
			return false;
		}
		if ($this->bev->input->length <= $o) {
			return '';
		}
		return $this->bev->input->substr($o, $n);
	}

	/**
	 * Read from buffer without draining
	 * @param  integer $o Offset
	 * @param  integer $n Number of bytes to read
	 * @return string|false
	 */
	public function substr($o, $n = -1) {
		if (!isset($this->bev)) {
			return false;
		}
		return $this->bev->input->substr($o, $n);
	}

	/**
	 * Searches first occurence of the string in input buffer
	 * @param  string  $what  Needle
	 * @param  integer $start Offset start
	 * @param  integer $end   Offset end
	 * @return integer        Position
	 */
	public function search($what, $start = 0, $end = -1) {
		return $this->bev->input->search($what, $start, $end);
	}

	/**
	 * Reads exact $n bytes from buffer
	 * @param  integer      $n Number of bytes to read
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

	/**
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
	 * @param  boolean $at_front At front. Default is true. If the front of a buffer is frozen, operations that drain data from the front of the buffer, or that prepend data to the buffer, will fail until it is unfrozen. If the back a buffer is frozen, operations that append data from the buffer will fail until it is unfrozen
	 * @return boolean           Success
	 */
	public function freezeInput($at_front = true) {
		if (isset($this->bev)) {
			return $this->bev->input->freeze($at_front);
		}
		return false;
	}

	/**
	 * Unfreeze input
	 * @param  boolean $at_front At front. Default is true. If the front of a buffer is frozen, operations that drain data from the front of the buffer, or that prepend data to the buffer, will fail until it is unfrozen. If the back a buffer is frozen, operations that append data from the buffer will fail until it is unfrozen
	 * @return boolean           Success
	 */
	public function unfreezeInput($at_front = true) {
		if (isset($this->bev)) {
			return $this->bev->input->unfreeze($at_front);
		}
		return false;
	}

	/**
	 * Freeze output
	 * @param  boolean $at_front At front. Default is true. If the front of a buffer is frozen, operations that drain data from the front of the buffer, or that prepend data to the buffer, will fail until it is unfrozen. If the back a buffer is frozen, operations that append data from the buffer will fail until it is unfrozen
	 * @return boolean           Success
	 */
	public function freezeOutput($at_front = true) {
		if (isset($this->bev)) {
			return $this->bev->output->unfreeze($at_front);
		}
		return false;
	}

	/**
	 * Unfreeze output
	 * @param  boolean $at_front At front. Default is true. If the front of a buffer is frozen, operations that drain data from the front of the buffer, or that prepend data to the buffer, will fail until it is unfrozen. If the back a buffer is frozen, operations that append data from the buffer will fail until it is unfrozen
	 * @return boolean           Success
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
	public function onWrite() {
	}

	/**
	 * Send data to the connection. Note that it just writes to buffer that flushes at every baseloop
	 * @param  string  $data Data to send
	 * @return boolean       Success
	 */
	public function write($data) {
		if (!$this->alive) {
			Daemon::log('Attempt to write to dead IOStream (' . get_class($this) . ')');
			return false;
		}
		if (!isset($this->bev)) {
			return false;
		}
		if (!strlen($data)) {
			return true;
		}
		$this->writing   = true;
		Daemon::$noError = true;
		if (!$this->bev->write($data) || !Daemon::$noError) {
			$this->close();
			return false;
		}
		return true;
	}

	/**
	 * Send data and appending \n to connection. Note that it just writes to buffer flushed at every baseloop
	 * @param  string  $data Data to send
	 * @return boolean       Success
	 */
	public function writeln($data) {
		if (!$this->alive) {
			Daemon::log('Attempt to write to dead IOStream (' . get_class($this) . ')');
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
	 * Finish the session. You should not worry about buffers, they are going to be flushed properly
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
	 * Close the connection
	 * @return void
	 */
	public function close() {
		if (!$this->freed) {
			$this->freed = true;
			if (isset($this->bev)) {
				$this->bev->free();
			}
			$this->bev = null;
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
		$this->fd  = null;
	}

	/**
	 * Send message to log
	 * @param  string $m Message
	 * @return void
	 */
	protected function log($m) {
		Daemon::log(get_class($this) . ': ' . $m);
	}

	/**
	 * Called when the connection has got new data
	 * @param  object $bev EventBufferEvent
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
		} catch (\Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
	}

	/**
	 * Called when new data received
	 * @return void
	 */
	protected function onRead() {
	}

	/**
	 * Called when the stream is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	protected function onReady() {
	}

	/**
	 * Push callback which will be called only once, when writing is available next time
	 * @param  callable $cb Callback
	 * @return void
	 */
	public function onWriteOnce($cb) {
		if (!$this->writing) {
			call_user_func($cb, $this);
			return;
		}
		$this->onWriteOnce->push($cb);
	}

	/**
	 * Called when the connection is ready to accept new data
	 * @param  object $bev EventBufferEvent
	 * @return void
	 */
	public function onWriteEv($bev) {
		if (Daemon::$config->logevents->value) {
			Daemon::log(get_class() . ' onWriteEv called');
		}
		$this->writing = false;
		if ($this->finished) {
			if ($this->bev->output->length === 0) {
				$this->close();
			}
			return;
		}
		if (!$this->ready) {
			$this->ready = true;
			while (!$this->onWriteOnce->isEmpty()) {
				try {
					$this->onWriteOnce->executeOne($this);
				} catch (\Exception $e) {
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
			} catch (\Exception $e) {
				Daemon::uncaughtExceptionHandler($e);
			}
		}
		else {
			$this->onWriteOnce->executeAll($this);
		}
		try {
			$this->onWrite();
		} catch (\Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
	}

	/**
	 * Called when the connection state changed
	 * @param  object  $bev    EventBufferEvent
	 * @param  integer $events Events
	 * @return void
	 */
	public function onStateEv($bev, $events) {
		if ($events & \EventBufferEvent::CONNECTED) {
			$this->onWriteEv($bev);
		}
		elseif ($events & (\EventBufferEvent::ERROR | \EventBufferEvent::EOF | \EventBufferEvent::TIMEOUT)) {
			try {
				if ($this->finished) {
					return;
				}
				if ($events & \EventBufferEvent::ERROR) {
					$errno = \EventUtil::getLastSocketErrno();
					if ($errno !== 0) {
						$this->log('Socket error #' . $errno . ':' . \EventUtil::getLastSocketError());
					}
					if ($this->ssl && $this->bev) {
						while ($err = $this->bev->sslError()) {
							$this->log('EventBufferEvent SSL error: ' . $err);
						}
					}
				}
				$this->finished = true;
				$this->onFinish();
				$this->close();
			} catch (\Exception $e) {
				Daemon::uncaughtExceptionHandler($e);
			}
		}
	}

	/**
	 * Moves arbitrary number of bytes from input buffer to given buffer
	 * @param  \EventBuffer $dest Destination nuffer
	 * @param  integer      $n    Max. number of bytes to move
	 * @return integer|false
	 */
	public function moveToBuffer(\EventBuffer $dest, $n) {
		if (!isset($this->bev)) {
			return false;
		}
		return $dest->appendFrom($this->bev->input, $n);
	}

	/**
	 * Moves arbitrary number of bytes from given buffer to output buffer
	 * @param  \EventBuffer $src Source buffer
	 * @param  integer      $n   Max. number of bytes to move
	 * @return integer|false
	 */
	public function writeFromBuffer(\EventBuffer $src, $n) {
		if (!isset($this->bev)) {
			return false;
		}
		$this->writing = true;
		return $this->bev->output->appendFrom($src, $n);
	}

	/**
	 * Read data from the connection's buffer
	 * @param  integer      $n Max. number of bytes to read
	 * @return string|false    Readed data
	 */
	public function read($n) {
		if ($n <= 0) {
			return '';
		}
		if (!isset($this->bev)) {
			return false;
		}
		$read = $this->bev->read($n);
		if ($read === null) {
			return false;
		}
		return $read;
	}

	/**
	 * Reads all data from the connection's buffer
	 * @return string Readed data
	 */
	public function readUnlimited() {
		if (!isset($this->bev)) {
			return false;
		}
		$read = $this->bev->read($this->bev->input->length);
		if ($read === null) {
			return false;
		}
		return $read;
	}
}
