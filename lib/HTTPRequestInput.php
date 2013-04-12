<?php
/**
 * HTTP request input buffer
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class HTTPRequestInput extends EventBuffer {

	/**
	 * Boundary
	 * @var string
	 */
	protected $boundary;

	/**
	 * Maximum file size from multi-part query
	 * @var integer
	 */
	protected $maxFileSize = 0;

	/**
	 * Readed
	 * @var integer
	 */
	protected $readed = 0;

	/**
	 * Frozen
	 * @var boolean
	 */
	protected $frozen = false;

	/**
	 * EOF
	 * @var boolean
	 */
	protected $EOF = false;

	/**
	 * Current Part
	 * @var hash
	 */
	public $curPart;

	/**
	 * Content dispostion of current Part
	 * @var array
	 */
	protected $curPartDisp = false;

	/**
	 * Related Request
	 * @var Request
	 */
	protected $req;

	/**
	 * State of multi-part processor
	 * @var integer (self::STATE_*)
	 */
	protected $state = self::STATE_SEEKBOUNDARY;

	/**
	 * Size of current upload chunk
	 * @var integer
	 */
	protected $curChunkSize;

	const STATE_SEEKBOUNDARY = 0;
	const STATE_HEADERS = 1;
	const STATE_BODY = 2;
	const STATE_UPLOAD = 3;

	/**
	 * Set boundary
	 * @param string
	 * @return void
	 */
	public function setBoundary($boundary) {
		$this->boundary = $boundary;
	}

	/**
	 * Freeze input
	 * @param boolean At front. Default is true. If the front of a buffer is frozen, operations that drain data from the front of the buffer, or that prepend data to the buffer, will fail until it is unfrozen. If the back a buffer is frozen, operations that append data from the buffer will fail until it is unfrozen.
	 * @return void
	 */
	public function freeze($at_front = false) {
		$this->frozen = true;
		//parent::freeze($at_front);
	}

	/**
	 * Unfreeze input
	 * @param boolean At front. Default is true. If the front of a buffer is frozen, operations that drain data from the front of the buffer, or that prepend data to the buffer, will fail until it is unfrozen. If the back a buffer is frozen, operations that append data from the buffer will fail until it is unfrozen.
	 * @return void
	 */
	public function unfreeze($at_front = false) {
		$this->frozen = false;
		//parent::unfreeze($at_front);
		$this->onRead();
		$this->req->checkIfReady();
	}

	/**
	 * Is frozen?
	 * @return boolean
	 */
	public function isFrozen() {
		return $this->frozen;
	}

	/**
	 * Is EOF?
	 * @return boolean
	 */
	public function isEof() {
		return $this->EOF;
	}

	/**
	 * Set request
	 * @param Request
	 * @return void
	 */
	public function setRequest(Request $req) {
		$this->req = $req;
	}

	/**
	 * onEOF
	 * @return void
	 */
	protected function onEOF() {
		if (!$this->req) {
			return;
		}
		$this->req->attrs->inputDone = true;
		$this->req->attrs->raw = '';
		if (($l = $this->length) > 0) {
		    $this->req->attrs->raw = $this->read($l);
		    if (isset($this->req->contype['application/x-www-form-urlencoded'])) {
				HTTPRequest::parse_str($this->req->attrs->raw, $this->req->attrs->post);
				unset($this->req->attrs->raw);
			}
		}
		$this->req->postPrepare();
		$this->req->checkIfReady();
	}

	/**
	 * onRead
	 * @return void
	 */
	protected function onRead() {
		if (!empty($this->boundary)) {
			$this->req->attrs->input->parseMultipart();
		}
		if (($this->req->attrs->contentLength <= $this->readed) && !$this->EOF) {
			$this->sendEOF();
		}
	}

	/**
	 * Send EOF
	 * @return void
	 */
	public function sendEOF() {
		if (!$this->EOF) {
			$this->EOF = true;
			$this->onEOF();
		}
	}

	/**
	 * Moves $n bytes from input buffer to arbitrary buffer
	 * @param EventBuffer Source nuffer
	 * @return integer
	 */
	public function readFromBuffer(EventBuffer $buf) {
		if (!$this->req) {
			return false;
		}
		$n = min($this->req->attrs->contentLength - $this->readed, $buf->length);
		if ($n > 0) {
			$m = $this->appendFrom($buf, $n);
			$this->readed += $m;
			if ($m > 0) {
				$this->onRead();
			}
		} else {
			$this->onRead();
			return 0;
		}
		return $m;
	}

	/**
	 * Append string to input buffer
	 * @param string Piece of request input
	 * @param [boolean true Final call is THIS SEQUENCE of calls (not mandatory final in request)?
	 * @return void
	 */
	public function readFromString($chunk, $final = true) {
		$this->add($chunk);
		$this->readed += strlen($chunk);
		if ($final) {
			$this->onRead();
		}
	}

	/**
	 * Parses multipart
	 * @return void
	 */
	public function parseMultipart() {
		start:
		if ($this->frozen) {
			return;
		}
		if ($this->state === self::STATE_SEEKBOUNDARY) {
			// seek to the nearest boundary
			if (($p = $this->search('--' . $this->boundary . "\r\n")) === false) {
				return;
			}
			// we have found the nearest boundary at position $p
			if ($p > 0) {
				$extra = $this->read($p);
				$this->log('parseBody(): SEEKBOUNDARY: got unexpected data before boundary (length = ' . $p . '): '.Debug::exportBytes($extra));
			}
			$this->drain(strlen($this->boundary) + 4); // drain
			$this->state = self::STATE_HEADERS;
		}
		if ($this->state === self::STATE_HEADERS) {
			// parse the part's headers
			$this->curPartDisp = false;
			$i = 0;
			do {
				$l = $this->readline(EventBuffer::EOL_CRLF);
				if ($l === null) {
					return;
				}
				if ($l === '') {
					break;
				}

				$e = explode(':', $l, 2);
				$e[0] = strtr(strtoupper($e[0]), HTTPRequest::$htr);
				if (isset($e[1])) {
					$e[1] = ltrim($e[1]);
				}
				if (($e[0] === 'CONTENT_DISPOSITION') && isset($e[1])) {
					HTTPRequest::parse_str($e[1], $this->curPartDisp, true);
					if (!isset($this->curPartDisp['form-data'])) {
						break;
					}
					if (!isset($this->curPartDisp['name'])) {
						break;
					}
					$this->curPartDisp['name'] = trim($this->curPartDisp['name'], '"');
					$name = $this->curPartDisp['name'];
					if (isset($this->curPartDisp['filename'])) {
						$this->curPartDisp['filename'] = trim($this->curPartDisp['filename'], '"');
						if (!ini_get('file_uploads')) {
							break;
						}
						$this->req->attrs->files[$name] = [
							'name'     => $this->curPartDisp['filename'],
							'type'     => '',
							'tmp_name' => null,
							'fp'	   => null,
							'error'    => UPLOAD_ERR_OK,
							'size'     => 0,
						];
						$this->curPart = &$this->req->attrs->files[$name];
						$this->req->onUploadFileStart($this);
						$this->state = self::STATE_UPLOAD;
					} else {
						$this->curPart = &$this->req->attrs->post[$name];
						$this->curPart = '';
					}
				}
				elseif (($e[0] === 'CONTENT_TYPE') && isset($e[1])) {
					if (isset($this->curPartDisp['name']) && isset($this->curPartDisp['filename'])) {
						$this->curPart['type'] = $e[1];
					}
				}
			} while ($i++ < 10);
			if ($this->state === self::STATE_HEADERS) {
				$this->state = self::STATE_BODY;
			}
			goto start;
		}
		if (($this->state === self::STATE_BODY) || ($this->state === self::STATE_UPLOAD)) {
			 // process the body
			$chunkEnd1 = $this->search("\r\n--" . $this->boundary . "\r\n");
			$chunkEnd2 = $this->search("\r\n--" . $this->boundary . "--\r\n");
			if ($chunkEnd1 === false && $chunkEnd2 === false) {
				/*  we have only piece of Part in buffer */
				$l = $this->length - strlen($this->boundary) - 8;
				if ($l <= 0) {
					return;
				}
				if (($this->state === self::STATE_BODY) && isset($this->curPartDisp['name'])) {
					$this->curPart .= $this->read($l);
				}
				elseif (($this->state === self::STATE_UPLOAD) && isset($this->curPartDisp['filename'])) {
					$this->curPart['size'] += $l;
					if ($this->req->getUploadMaxSize() < $this->curPart['size']) {
						$this->curPart['error'] = UPLOAD_ERR_INI_SIZE;
						$this->req->header('413 Request Entity Too Large');
						$this->req->out('');
						$this->req->finish();
					}
					elseif ($this->maxFileSize && ($this->maxFileSize < $this->curPart['size'])) {
						$this->curPart['error'] = UPLOAD_ERR_FORM_SIZE;
						$this->req->header('413 Request Entity Too Large');
						$this->req->out('');
						$this->req->finish();
					}
					else {
						$this->curChunkSize = $l;
						$this->req->onUploadFileChunk($this);
					}
				}
			}
			else {
				if ($chunkEnd1 === false) {
					$l = $chunkEnd2;
					$endOfMsg = true;
				} else {
					$l = $chunkEnd1;
					$endOfMsg = false;
				}
				/* we have entire Part in buffer */
				if (
					($this->state === self::STATE_BODY)
					&& isset($this->curPartDisp['name'])
				) {
					$this->curPart .= $this->read($l);
					if ($this->curPartDisp['name'] === 'MAX_FILE_SIZE') {
						$this->maxFileSize = (int) $chunk;
					}
				}
				elseif (
					($this->state === self::STATE_UPLOAD)
					&& isset($this->curPartDisp['filename'])
				) {
					$this->curPart['size'] += $l;
					$this->curChunkSize = $l;
					$this->req->onUploadFileChunk($this, true);
				}

				if ($endOfMsg) { // end of whole message
					$this->state = self::STATE_SEEKBOUNDARY;
					$this->bodyDone = true;
				} else {
					$this->state = self::STATE_HEADERS; // let's read the next part
					goto start;
				}
			}
		}
	}

	/**
	 * Get current upload chunk as string
	 * @return string Chunk body
	 */
	public function getChunkString() {
		if (!$this->curChunkSize) {
			return false;
		}
		$chunk = $this->read($this->curChunkSize);
		$this->curChunkSize = null;
		return $chunk;
	}


	/**
	 * Write current upload chunk to file descriptor
	 * @param mixed File destriptor
	 * @param callable Callback
	 * @return boolean Success
	 */
	public function writeChunkToFd($fd, $cb = null) {
		return false; // It is not supported yet (callback missing in EventBuffer->write())
		if (!$this->curChunkSize) {
			return false;
		}
		$this->write($fd, $this->curChunkSize);
		$this->curChunkSize = null;
		return true;
	}

	/**
	 * Log
	 * @param string Message
	 * @return void
	 */
	public function log($msg) {
		Daemon::log(get_class($this) . ': ' . $msg);
	}
}
