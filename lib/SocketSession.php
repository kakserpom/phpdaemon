<?php

/**
 * Socket session
 * @deprecated
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class SocketSession {

	public $buf = '';
	public $connId;
	public $EOL = "\n";

	// @todo make private and add new method ->getApplication()
	public $appInstance;

	// @todo migrate to constants
	public $state = 0;

	// @todo not great
	public $finished = FALSE;
	public $readLocked = FALSE;
	public $addr;

	/**
	 * SocketSession constructor
	 * @param integer Connection's ID
	 * @param object AppInstance
	 * @return void
	 */
	public function __construct($connId, $appInstance) {
		$this->connId = $connId;
		$this->appInstance = $appInstance;
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

		if ($p === FALSE) {
			return FALSE;
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

		return TRUE;
	}

	/** 
	 * Lock read
	 * @todo add more description
	 * @return void
	 */
	public function lockRead() {
		$this->readLocked = TRUE;
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

		$this->readLocked = FALSE;
		$this->appInstance->onReadEvent(NULL, array($this->connId));
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
		return $this->appInstance->write($this->connId, $s);
	}

	/**
	 * Send data and appending \n to connection. Note that it just writes to buffer that flushes at every baseloop
	 * @param string Data to send.
	 * @return boolean Success.
	 */
	public function writeln($s) {
		return $this->appInstance->write($this->connId, $s . $this->EOL);
	}

	/**
	 * Finish the session. You shouldn't care about pending buffers, it will be flushed properly.
	 * @return void
	 */
	public function finish() {
		if ($this->finished) {
			return;
		}

		$this->finished = TRUE;
		$this->onFinish();
		$this->appInstance->finishConnection($this->connId);
	}

	/**
	 * Called when the session finished
	 * @todo protected?
	 * @return void
	 */
	public function onFinish() {
		unset($this->appInstance->sessions[$this->connId]);
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

}
