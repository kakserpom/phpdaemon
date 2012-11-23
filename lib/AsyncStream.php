<?php

/**
 * Asynchronous stream
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class AsyncStream {

	public $readFD;
	public $writeFD;
	public $readBuf;
	public $writeBuf;
	public $onRead;
	public $onReadData;
	public $onWrite;
	public $onReadFailure;
	public $onWriteFailure;
	public $onEOF;
	public $EOF = FALSE;
	public $readPriority = 10;
	public $writePriority = 10;
	public $request;
	public $readPacketSize = 4096;
	public $done = FALSE;
	public $writeState = FALSE;
	public $finishWrite = FALSE;
	public $buf = '';
	public $noEvents = FALSE;
	public $fileMode = FALSE;
	public $filePath;
	public $useSockets = true;

	public function __construct($readFD = NULL, $writeFD = NULL) {
		$this->initStream($readFD, $writeFD);
	}
	
	public function gets() {
		$p = strpos($this->buf, "\n");

		if ($p === FALSE) {
			return FALSE;
		}
		
		$r = binarySubstr($this->buf, 0, $p + 1);
		$this->buf = binarySubstr($this->buf, $p + 1);

		return $r;
	}
	
	public function initStream($readFD = NULL,$writeFD = NULL) {
		if (is_string($readFD)) {
			$u = parse_url($url = $readFD);
		
			if ($u['scheme'] === 'unix') {
				if (!$this->useSockets) {
					$readFD = stream_socket_client($readFD, $errno, $errstr, 1);
				} else {
					$readFD = socket_create(AF_UNIX, SOCK_STREAM, 0);

					if (!socket_connect($readFD, substr($url, 7))) {
						socket_close($readFD);
						$readFD = FALSE;
					}
				}
			}
			elseif (
				($u['scheme'] === 'tcp') 
				|| ($u['scheme'] === 'tcpstream')
			) {
				if (
					!$this->useSockets 
					|| ($u['scheme'] === 'tcpstream')
				) {
					$readFD = stream_socket_client('tcp://' . substr($readFD, 12), $errno, $errstr, 1);
				} else {
					$readFD = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

					if (!socket_connect($readFD, $u['host'], $u['port'])) {
						socket_close($readFD);
						$readFD = FALSE;
					}
				}
			}
			elseif ($u['scheme'] === 'udp') {
				if (!$this->useSockets) {
					$readFD = stream_socket_client($readFD, $errno, $errstr, 1);
				} else {
					$readFD = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

					if (!socket_connect($readFD, $u['host'], $u['port'])) {
						socket_close($readFD);
						$readFD = FALSE;
					}
				}
			}
			elseif ($u['scheme'] === 'file') {
				$this->filePath = substr($url, 7);
				$readFD = @fopen($this->filePath, 'r');
				$this->fileMode = TRUE;
			}
		}
	
		if ($readFD !== NULL) {
			$this->setFD($readFD,$writeFD);
		}

		return $readFD !== FALSE;
	}
	
	public function setReadPacketSize($n) {
		$this->readPacketSize = $n;

		return $this;
	}

	public function finishWrite() {
		if (!$this->writeState) {
			$this->closeWrite();
		}

		$this->finishWrite = TRUE;

		return TRUE;
	}

	public function setFD($readFD, $writeFD = NULL) {
		$this->readFD = $readFD;
		$this->writeFD = $writeFD;

		if (!is_resource($this->readFD)) {
			throw new BadStreamDescriptorException('wrong readFD', 1);
		}
	
		if ($this->readBuf === NULL) {
			if (!stream_set_blocking($this->readFD, 0)) {
				throw new Exception('setting blocking for read stream failed');
			}

			$this->readBuf = event_buffer_new(
				$this->readFD,
				array($this, 'onReadEvent'),
				array($this, 'onWriteEvent'),
				array($this, 'onReadFailureEvent'),
				array()
			);

			if (!$this->readBuf) {
				throw new Exception('creating read buffer failed');
			}

			if (!event_buffer_base_set($this->readBuf, Daemon::$process->eventBase)) {
				throw new Exception('wrong base');
			}

			if (
				(event_buffer_priority_set($this->readBuf, $this->readPriority) === FALSE) 
				&& FALSE
			) {
				throw new Exception('setting priority for read buffer failed');
			}
		} else {
			if (!stream_set_blocking($this->readFD, 0)) {
				throw new Exception('setting blocking for read stream failed');
			}

			if (!event_buffer_fd_set($this->readBuf, $this->readFD)) {
				throw new Exception('setting descriptor for write buffer failed');
			}
		}

		if ($this->writeFD === NULL) {
			return $this;
		}

		if (!is_resource($this->writeFD)) {
			throw new BadStreamDescriptorException('wrong writeFD',1);
		}

		if ($this->writeBuf === NULL) {
			if (!stream_set_blocking($this->writeFD, 0)) {
				throw new Exception('setting blocking for write stream failed');
			}

			$this->writeBuf = event_buffer_new(
				$this->writeFD,
				NULL,
				array($this, 'onWriteEvent'),
				array($this, 'onWriteFailureEvent'),
				array()
			);
			
			if (!$this->writeBuf) {
				throw new Exception('creating write buffer failed');
			}

			if (!event_buffer_base_set($this->writeBuf, Daemon::$process->eventBase)) {
				throw new Exception('wrong base');
			}

			if (
				(event_buffer_priority_set($this->writeBuf, $this->writePriority) === FALSE) 
				&& FALSE
			) {
				throw new Exception('setting priority for write buffer failed');
			}
		} else {
			stream_set_blocking($this->writeFD, 0);
			event_buffer_fd_set($this->buf, $this->writeFD);
		}
	
		return $this;
	}
	
	public function closeRead() {
		if (is_resource($this->readBuf)) {
			if (event_buffer_free($this->readBuf) === FALSE) {
				$this->readBuf = FALSE;

				throw new Exception('freeing read buffer failed.');
			}
		
			$this->readBuf = FALSE;
		}
		
		if ($this->readFD) {
			fclose($this->readFD);
			$this->readFD = FALSE;
		}

		return $this;
	}

	public function closeWrite() {
		if (is_resource($this->writeBuf)) {
			if (event_buffer_free($this->writeBuf) === FALSE) {
				$this->writeBuf = FALSE;

				throw new Exception('freeing write buffer failed.');
			}

			$this->writeBuf = FALSE;
		}

		if ($this->writeFD) {
			fclose($this->writeFD);
			$this->writeFD = FALSE;
		}

		return $this;
	}

	public function close() {
		$this->closeRead();
		$this->closeWrite();
	}

	public function onRead($cb = NULL) {
		$this->onRead = $cb;

		return $this;
	}

	public function onReadData($cb = NULL) {
		$this->onReadData = $cb;

		return $this;
	}

	public function onWrite($cb = NULL) {
		$this->onWrite = $cb;
		
		return $this;
	}
	
	public function onFailure($read = NULL, $write = NULL) {
		if ($write === NULL) {
			$write = $read;
		}

		$this->onReadFailure = $read;
		$this->onWriteFailure = $write;

		return $this;
	}

	public function onEOF($cb = NULL) {
		$this->onEOF = $cb;

		return $this;
	}

	public function enable() {
		$mode = EV_READ | EV_PERSIST;

		if ($this->writeBuf === NULL) {
			$mode |= EV_WRITE;
		}
		
		if (!event_buffer_enable($this->readBuf, $mode)) {
			if ($this->fileMode) {
				$this->noEvents = TRUE;
			} else {
				throw new Exception('enabling read buffer failed');
			}
		}
		
		if ($this->writeBuf !== NULL) {
			if (!event_buffer_enable($this->writeBuf, EV_WRITE | EV_PERSIST)) {
				throw new Exception('enabling write buffer failed');
			}
		}
		
		return $this;
	}
	
	public function setPriority($read, $write) {
		$this->readPriority = $read;
		$this->writePriority = $write;

		if ($this->readBuf !== NULL) {
			if (event_buffer_priority_set($this->readBuf, $this->readPriority) === FALSE) {
				throw new Exception('setting priority for read buffer failed');
			}
		}
		
		if ($this->writeBuf !== NULL) {
			if (event_buffer_priority_set($this->writeBuf, $this->writePriority) === FALSE) {
				throw new Exception('setting priority for read buffer failed');
			}
		}
		
		return $this;
	}
	
	public function setRequest($request) {
		$this->request = $request;

		return $this;
	}
	
	public function readMask($low = NULL, $high = NULL) {
		if ($low === NULL) {
			$low = 1;
		}
		
		if ($high === NULL) {
			$high = 0xFFFFFF;
		}
		
		if (!event_buffer_watermark_set($this->readBuf, EV_READ, $low, $high)) {
			throw new Exception('readMask(' . $low . ',' . $high . ') failed.');
		}
		
		return $this;
	}

	public function writeMask($low = NULL, $high = NULL) {
		if ($low === NULL) {
			$low = 1;
		}
		
		if ($high === NULL) {
			$high = 0xFFFFFF;
		}
		
		if (!event_buffer_watermark_set($this->writeBuf, EV_WRITE, $low, $high)) {
			throw new Exception('writeMask(' . $low . ',' . $high . ') failed.');
		}
		
		return $this;
	}
	
	public function read($n = NULL) {
		if ($n === NULL) {
			$n = $this->readPacketSize;
		}
		
		if ($this->noEvents) {
			if (!$this->readFD) {
				return '';
			}

			$data = fread($this->readFD, $n);

			if ($data === FALSE) {
				return '';
			}

			return $data;
		}
	
		if ($this->readBuf === FALSE) {
			return '';
		}
	
		$r = event_buffer_read($this->readBuf, $n);

		if ($r === NULL) {
			$r = '';
		}

		if ($r === FALSE) {
			throw new Exception('read buffer failed.');
		}

		return $r;
	}


	public function write($s) {
		$b = ($this->writeBuf !== NULL) ? $this->writeBuf : $this->readBuf;
		
		if ($b === FALSE) {
			return $this;
		}
		
		if ($s !== '') {
			$this->writeState = TRUE;
		}
		
		if (!event_buffer_write($b, $s)) {
			throw new Exception('write() failed.');
		}
		
		return $this;
	}

	public function onEofEvent() {
		$this->EOF = TRUE;
	
		if ($this->onEOF !== NULL) {
			call_user_func($this->onEOF, $this);
		}
	
		$this->closeRead();
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
		elseif (
			!$this->EOF 
			&& $this->noEvents
		) {
			$this->onReadEvent();
		}

		return $this->EOF;
	}

	public function onReadEvent($buf = NULL, $arg = NULL) {
		if (Daemon::$config->logevents->value) {
			Daemon::log(__METHOD__ . '()');
		}
		
		if ($this->onReadData !== NULL) {
			while (($data = $this->read()) !== '') {
				if ($data === FALSE) {
					throw new Exception('read() returned false');
				}

				call_user_func($this->onReadData, $this, $data);
			}

			$this->eof();
		}
		elseif ($this->onRead !== NULL) {
			call_user_func($this->onRead, $this);
		}
	}

	public function onWriteEvent($buf, $arg = NULL) {
		if (Daemon::$config->logevents->value) {
			Daemon::log(__METHOD__ . '()');
		}
		
		$this->writeState = FALSE;
		
		if ($this->onWrite !== NULL) {
			call_user_func($this->onWrite, $this);
		}
		
		if ($this->finishWrite) {
			$this->closeWrite();
		}
	}
	
	public function onReadFailureEvent($buf, $arg = NULL) {
		if (Daemon::$config->logevents->value) {
			Daemon::log(__METHOD__ . '()');
		}

		if ($this->onReadFailure !== NULL) {
			call_user_func($this->onReadFailure, $this);
		}
	
		event_base_loopexit(Daemon::$process->eventBase);
	
		$this->closeRead();
	}

	public function onWriteFailureEvent($buf, $arg = NULL) {
		if (Daemon::$config->logevents->value) {
			Daemon::log(__METHOD__ . '()');
		}

		if ($this->onWriteFailure !== NULL) {
			call_user_func($this->onWriteFailure, $this);
		}
	
		event_base_loopexit(Daemon::$process->eventBase);
		$this->closeWrite();
	}
}

class BadStreamDescriptorException extends Exception {}
