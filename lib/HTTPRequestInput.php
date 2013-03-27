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
	 * Frozen
	 * @var boolean
	 */
	protected $frozen = false;

	/**
	 * Current Part
	 * @var hash
	 */
	protected $curPart;

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
		parent::freeze($at_front);
	}

	/**
	 * Unfreeze input
	 * @param boolean At front. Default is true. If the front of a buffer is frozen, operations that drain data from the front of the buffer, or that prepend data to the buffer, will fail until it is unfrozen. If the back a buffer is frozen, operations that append data from the buffer will fail until it is unfrozen.
	 * @return void
	 */
	public function unfreeze($at_front = false) {
		$this->frozen = false;
		parent::unfreeze($at_front);
	}

	/**
	 * Is frozen?
	 * @return boolean
	 */
	public function isFrozen() {
		return $this->frozen;
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
	 * Parse request body
	 * @return void
	 */
	public function parse() {
		if (empty($this->boundary)) {
			return;
		}
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
				$this->remove($extra, $p);
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
				if (($e[0] == 'CONTENT_DISPOSITION') && isset($e[1])) {
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
						$this->req->onUploadFileStart($this->curPart);
						$this->state = self::STATE_UPLOAD;
					} else {
						$this->curPart = &$this->req->attrs->post[$name];
						$this->curPart = '';
					}
				}
				elseif (($e[0] == 'CONTENT_TYPE') && isset($e[1])) {
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
				$this->remove($chunk, $l);
				if (($this->state === self::STATE_BODY) && isset($this->curPartDisp['name'])) {
					$this->curPart .= $chunk;
				}
				elseif (($this->state === self::STATE_UPLOAD) && isset($this->curPartDisp['filename'])) {
					if (!isset($this->req->attrs->files[$this->curPartDisp['name']]['fp'])) {
						return; // fd is not ready yet, interrupt
					}
					$this->curPart['size'] += $l;
					if ($this->req->getUploadMaxSize() < $this->curPart['size']) {
						$this->curPart['error'] = UPLOAD_ERR_INI_SIZE;
					}
					if ($this->maxFileSize && ($this->maxFileSize < $this->curPart['size'])) {
						$this->curPart['error'] = UPLOAD_ERR_FORM_SIZE;
					}
					$this->req->onUploadFileChunk($this->curPart, $chunk);
				}
			}
			else {
				if ($chunkEnd1 === false) {
					$p = $chunkEnd2;
					$endOfMsg = true;
				} else {
					$p = $chunkEnd1;
					$endOfMsg = false;
				}
				/* we have entire Part in buffer */
				$this->remove($chunk, $p);
				if (
					($this->state === self::STATE_BODY)
					&& isset($this->curPartDisp['name'])
				) {
					$this->curPart .= $chunk;
					if ($this->curPartDisp['name'] === 'MAX_FILE_SIZE') {
						$this->maxFileSize = (int) $chunk;
					}
				}
				elseif (
					($this->state === self::STATE_UPLOAD)
					&& isset($this->curPartDisp['filename'])
				) {
					if (!isset($this->curPart['fp'])) {
						return; // fd is not ready yet, interrupt
					}
					$this->curPart['size'] += $p;
					$this->req->onUploadFileChunk($this->curPart, $chunk, true);
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
	 * Log
	 * @param string Message
	 * @return void
	 */
	public function log($msg) {
		Daemon::log(get_class($this) . ': ' . $msg);
	}
}