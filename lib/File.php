<?php

/**
 * Connection
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
//@todo aio_* support
class File extends IOStream {
	public $directInput = true;
	public $priority = 10; // low priority
	public static function open($path, $mode = 'rb') {
		$fd = fopen($path, $mode);
		if (!$fd) {
			return false;
		}
		stream_set_blocking($fd, 0);
		return new File($fd);
	}
	
	public function setFd($fd) {
		$this->fd = $fd;
		if ($this->directInput) {
			$ev = event_new();
			if (!event_set($ev, $this->fd, EV_READ | EV_PERSIST, array($this, 'onReadEvent'))) {
				Daemon::log(get_class($this) . '::' . __METHOD__ . ': Couldn\'t set event on '.Daemon::dump($fd));
				return;
			}
			event_base_set($ev, Daemon::$process->eventBase);
			if ($this->priority !== null) {
				event_priority_set($ev, $this->priority);
			}
			event_add($ev);
			$this->readEvent = $ev;
		}
		$this->buffer = event_buffer_new($this->fd,	$this->directInput ? NULL : array($this, 'onReadEvent'), array($this, 'onWriteEvent'), array($this, 'onFailureEvent'));
		event_buffer_base_set($this->buffer, Daemon::$process->eventBase);
		if ($this->priority !== null) {
			event_buffer_priority_set($this->buffer, $this->priority);
		}
		event_buffer_watermark_set($this->buffer, EV_READ, $this->lowMark, $this->highMark);
		event_buffer_enable($this->buffer, $this->directInput ? (EV_WRITE | EV_PERSIST) : (EV_READ | EV_WRITE | EV_PERSIST));
		
		if (!$this->inited) {
			$this->inited = true;
			$this->init();
		}
	}
	
	public function seek($p) {
		fseek($this->fd, $p);
	}
	public function tell() {
		return ftell($this->fd);
	}
	/**
	 * Read data from the connection's buffer
	 * @param integer Max. number of bytes to read
	 * @return string Readed data
	 */
	public function read($n) {
		if (isset($this->readEvent)) {
			if (!isset($this->fd)) {
				return false;
			}
			$read = fread($this->fd, $n);
			Daemon::log(Debug::dump($read));
		} else {
			if (!isset($this->buffer)) {
				return false;
			}
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
	
	public function closeFd() {
		fclose($this->fd);
	}
	
	public function eof() {
		if (
			!$this->EOF && (
				($this->readFD === FALSE) 
				|| feof($this->readFD)
			)
		) {
			$this->onEofEvent();
		}
		elseif (!$this->EOF) {
			$this->onReadEvent();
		}

		return $this->EOF;
	}

	public function onEofEvent() {
		$this->EOF = true;
	
		if ($this->onEOF !== NULL) {
			call_user_func($this->onEOF, $this);
		}
	
		$this->close();
	}
}
