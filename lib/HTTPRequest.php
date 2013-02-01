<?php

/**
 * HTTP request
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class HTTPRequest extends Request {

	/**
	 * Status codes
	 * @var array
	 */
	private static $codes = array (
		100 => 'Continue',
		101 => 'Switching Protocols',
		200 => 'OK',
		201 => 'Created',
		202 => 'Accepted',
		203 => 'Non-Authoritative Information',
		204 => 'No Content',
		205 => 'Reset Content',
		206 => 'Partial Content',
		300 => 'Multiple Choices',
		301 => 'Moved Permanently',
		302 => 'Found',
		303 => 'See Other',
		304 => 'Not Modified',
		305 => 'Use Proxy',
		306 => '(Unused)',
		307 => 'Temporary Redirect',
		400 => 'Bad Request',
		401 => 'Unauthorized',
		402 => 'Payment Required',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		406 => 'Not Acceptable',
		407 => 'Proxy Authentication Required',
		408 => 'Request Timeout',
		409 => 'Conflict',
		410 => 'Gone',
		411 => 'Length Required',
		412 => 'Precondition Failed',
		413 => 'Request Entity Too Large',
		414 => 'Request-URI Too Long',
		415 => 'Unsupported Media Type',
		416 => 'Requested Range Not Satisfiable',
		417 => 'Expectation Failed',
		500 => 'Internal Server Error',
		501 => 'Not Implemented',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
		504 => 'Gateway Timeout',
		505 => 'HTTP Version Not Supported',
	);

	// @todo phpdoc needed
	public $oldFashionUploadFP = false;
	public $answerlen = 0;
	public $contentLength;
	private $cookieNum = 0;

	public static $hvaltr = array(';' => '&', ' ' => '');
	public static $htr = array('-' => '_');

	public $mpartstate = 0;
	public $mpartoffset = 0;
	public $mpartcondisp = false;
	public $headers = array('STATUS' => '200 OK');
	public $headers_sent = false; // @todo make private
	private $headers_sent_file;
	private $headers_sent_line;
	private $boundary = false;
	public $sendfp;
	
	const MPSTATE_SEEKBOUNDARY = 0;
	const MPSTATE_HEADERS = 1;
	const MPSTATE_BODY = 2;
	const MPSTATE_UPLOAD = 3;

	/**
	 * Preparing before init
	 * @todo protected?
	 * @param object Source request
	 * @return void
	 */
	public function preinit($req) {
		if ($req === null) {
			$req = new \stdClass;
			$req->attrs = new \stdClass;
			$req->attrs->stdin_done = true;
			$req->attrs->params_done = true;
			$req->attrs->chunked = false;
		}

		$this->attrs = $req->attrs;

		if ($this->upstream->pool->config->expose->value) {
			$this->header('X-Powered-By: phpDaemon/' . Daemon::$version);
		}

		$this->parseParams();
	}
	
	public function sendfile($path, $cb, $pri = EIO_PRI_DEFAULT) {
		$req = $this;
		if ($req->state === self::STATE_FINISHED) {
			return;
		}
		try {
			$req->header('Content-Type: ' . MIME::get($path));
		} catch (RequestHeadersAlreadySent $e) {}
		if ($req->upstream->sendfileCap) {
			$req->ensureSentHeaders();
			$req->upstream->onWriteOnce(function($conn) use ($req, $path, $cb, $pri) {
				FS::sendfile($req->upstream->fd, $path, $cb, 0, null, $pri);
			});
			return;
		}
		$first = true;
		FS::readfileChunked($path, $cb,
			function($file, $chunk) use ($req, &$first) { // readed chunk
				if ($first) {
					try {
						$req->header('Content-Length: ' . $file->stat['size']);
					} catch (RequestHeadersAlreadySent $e) {}
					$first = false;
				}
				$req->out($chunk);
			}
		);
	}
	
	/**
	 * Called by call() to check if ready
	 * @todo protected?
	 * @return void
	 */
	public function checkIfReady() {
		if (
			!$this->attrs->params_done
			|| !$this->attrs->stdin_done
		) {
			return false;
		} else {
			if (isset($this->appInstance->passphrase)) {
				if (
					!isset($this->attrs->server['PASSPHRASE'])
					|| ($this->appInstance->passphrase !== $this->attrs->server['PASSPHRASE'])
				) {
					$this->finish();
				}
			}
		}
		return true;
	}

	/**
	 * Parses GET-query string and other request's headers
	 * @todo private?
	 * @return void
	 */
	public function parseParams() {
		if (
			isset($this->attrs->server['CONTENT_TYPE'])
			&& !isset($this->attrs->server['HTTP_CONTENT_TYPE'])
		) {
			$this->attrs->server['HTTP_CONTENT_TYPE'] = $this->attrs->server['CONTENT_TYPE'];
		}

		if (isset($this->attrs->server['QUERY_STRING'])) {
			self::parse_str($this->attrs->server['QUERY_STRING'], $this->attrs->get);
		}

		if (
			isset($this->attrs->server['REQUEST_METHOD'])
			&& ($this->attrs->server['REQUEST_METHOD'] == 'POST')
			&& isset($this->attrs->server['HTTP_CONTENT_TYPE'])
		) {
			self::parse_str($this->attrs->server['HTTP_CONTENT_TYPE'], $this->contype, true);
			$found = false;
			foreach ($this->contype as $k => $v) {
				if (strpos($k, '/') === false) {
					continue;
				}
				if (!$found) {
					$found = true;
				} else {
					unset($this->contype[$k]);
				}
			}

			if (
				isset($this->contype['multipart/form-data'])
				&& (isset($this->contype['boundary']))
			) {
				$this->boundary = $this->contype['boundary'];
			}
		}

		if (isset($this->attrs->server['HTTP_COOKIE'])) {
			self::parse_str($this->attrs->server['HTTP_COOKIE'], $this->attrs->cookie, true);
		}

		if (isset($this->attrs->server['HTTP_AUTHORIZATION'])) {
			$e = explode(' ', $this->attrs->server['HTTP_AUTHORIZATION'], 2);

			if (
				($e[0] == 'Basic')
				&& isset($e[1])
			) {
				$e[1] = base64_decode($e[1]);
				$e = explode(':', $e[1], 2);

				if (isset($e[1])) {
					list($this->attrs->server['PHP_AUTH_USER'], $this->attrs->server['PHP_AUTH_PW']) = $e;
				}
			}
		}

		$this->onParsedParams();
	}

	/**
	 * Prepares the request body
	 * @return void
	 */
	public function postPrepare() {
		if (
			isset($this->attrs->server['REQUEST_METHOD'])
			&& ($this->attrs->server['REQUEST_METHOD'] == 'POST')
		) {
			if (isset($this->contype['application/x-www-form-urlencoded'])) {
				self::parse_str($this->attrs->stdinbuf, $this->attrs->post);
			}

			if (isset($this->attrs->server['REQUEST_PREPARED_UPLOADS']) && $this->attrs->server['REQUEST_PREPARED_UPLOADS'] === 'nginx') {
				if (isset($this->attrs->server['REQUEST_PREPARED_UPLOADS_URL_PREFIX'])) {
					$URLprefix = $this->attrs->server['REQUEST_PREPARED_UPLOADS_URL_PREFIX'];
					$l = strlen($URLprefix);
					foreach (array('PHP_SELF', 'REQUEST_URI', 'SCRIPT_NAME', 'DOCUMENT_URI') as $k) {
						if (!isset($this->attrs->server[$k])) {continue;}
						if (strncmp($this->attrs->server[$k], $URLprefix, $l) === 0) {
							$this->attrs->server[$k] = substr($this->attrs->server[$k], $l - 1);
						}
					}
				}

				$prefix = 'file.';
				$prefixlen = strlen($prefix);
				foreach ($this->attrs->post as $k => $v) {
					if (strncmp($k, $prefix, $prefixlen) === 0) {
						$e = explode('.', substr($k, $prefixlen));
						if (!isset($this->attrs->files[$e[0]])) {
							$this->attrs->files[$e[0]] = array('error' => UPLOAD_ERR_OK);
						}
						$this->attrs->files[$e[0]][$e[1]] = $v;
					}
				}
				foreach ($this->attrs->files as $k => &$file) {
					if (!isset($file['tmp_name'])
						|| !isset($file['name'])
						|| !ctype_digit(basename($file['tmp_name']))
						|| pathinfo($file['tmp_name'], PATHINFO_DIRNAME) !== ini_get('upload_tmp_dir'))
					{
						unset($this->attrs->files[$k]);
						continue;
					}
					if ($this->oldFashionUploadFP) {
						$file['fp'] = fopen($file['tmp_name'], 'c+');
					} else {
						FS::open($file['tmp_name'], 'c+', function ($fp) use (&$file) {
							if (!$fp) {
								return;
							}
							$file['fp'] = $fp;
						});
					}
					unset($file);
				}
			}

			if (
				isset($this->attrs->server['REQUEST_BODY_FILE'])
				&& $this->upstream->pool->config->autoreadbodyfile->value
			) {
				$this->readBodyFile();
			}
		}
	}

	/**
	 * @description Called when new piece of request's body is received
	 * @param string Piece of request body
	 * @return void
	 */
	public function stdin($c) {
		/*Daemon::log(Debug::dump([
			$this->attrs->server['REQUEST_METHOD'],
			$this->attrs->server,
			$c
		]));*/
		if ($c !== '') {
			$this->attrs->stdinbuf .= $c;
			$this->attrs->stdinlen += strlen($c);
		}
		if (
			!isset($this->attrs->server['HTTP_CONTENT_LENGTH'])
			|| ($this->attrs->server['HTTP_CONTENT_LENGTH'] <= $this->attrs->stdinlen)
		) {
			$this->attrs->stdin_done = true;
			$this->postPrepare();
			if ($this->sleepTime === 0) {
				$this->wakeup();
			}
		}
		$this->parseStdin();
	}

	public function ensureSentHeaders() {
		if (!$this->headers_sent) {
			if (isset($this->headers['STATUS'])) {
				$h = (isset($this->attrs->noHttpVer) && ($this->attrs->noHttpVer) ? 'Status: ' : $this->attrs->server['SERVER_PROTOCOL']) . ' ' . $this->headers['STATUS'] . "\r\n";
			} else {
				$h = '';
			}

			if ($this->attrs->chunked) {
				$this->header('Transfer-Encoding: chunked');
			}

			foreach ($this->headers as $k => $line) {
				if ($k !== 'STATUS') {
					$h .= $line . "\r\n";
				}
			}

			$h .= "\r\n";
			$this->headers_sent_file = __FILE__;
			$this->headers_sent_line = __LINE__;
			$this->headers_sent = true;
			$this->upstream->requestOut($this, $h);
			return false;
		}
		return true;
	}
	
	/**
	 * Output some data
	 * @param string String to out
	 * @return boolean Success
	 */
	public function out($s, $flush = true) {
		if ($flush) {
			if (!Daemon::$obInStack) { // preventing recursion
				ob_flush();
			}
		}

		if ($this->aborted) {
			return false;
		}
		if (!isset($this->upstream)) {
				return false;
		}
		
		$l = strlen($s);
		$this->answerlen += $l;

		$this->ensureSentHeaders();
		
		if ($this->attrs->chunked) {
			for ($o = 0; $o < $l;) {
				$c = min($this->upstream->pool->config->chunksize->value, $l - $o);

				$chunk = dechex($c) . "\r\n"
					. ($c === $l ? $s : binarySubstr($s, $o, $c)) // content
					. "\r\n";

				if ($this->sendfp) {
					$this->sendfp->write($chunk);
				} else {
					$this->upstream->requestOut($this, $chunk);
				}

				$o += $c;
			}
		} else {
			if ($this->sendfp) {
				$this->sendfp->write($s);
				return true;
			}

			if (Daemon::$compatMode) {
				echo $s;
				return true;
			}

			return $this->upstream->requestOut($this, $s);
		}
	}

	/**
	 * Called when request's headers parsed
	 * @return void
	 */
	public function onParsedParams() { }

	/**
	 * Outputs data with headers (split by \r\n\r\n)
	 * @todo description missed (what is the difference between this and out()) ?
	 * @param string String to out
	 * @return boolean Success.
	 */
	public function combinedOut($s) {
		if (!$this->headers_sent) {
			$e = explode("\r\n\r\n", $s, 2);
			$h = explode("\r\n", $e[0]);

			foreach ($h as $l) {
				$this->header($l);
			}

			if (isset($e[1])) {
				return $this->out($e[1]);
			}

			return true;
		} else {
			return $this->out($s);
		}
	}

	/**
	 * Use chunked encoding
	 * @return void
	 */
	public function chunked() {
		$this->header('Transfer-Encoding: chunked');
		$this->attrs->chunked = true;
	}

	/**
	 * Called when the request wakes up
	 * @return void
	 */
	public function onWakeup() {
		parent::onWakeup();
		if (!Daemon::$obInStack) { // preventing recursion
			@ob_flush();
		}
		$_GET     = &$this->attrs->get;
		$_POST    = &$this->attrs->post;
		$_COOKIE  = &$this->attrs->cookie;
		$_REQUEST = &$this->attrs->request;
		$_SESSION = &$this->attrs->session;
		$_FILES   = &$this->attrs->files;
		$_SERVER  = &$this->attrs->server;
	}


	/**
	 * Called when the request starts sleep
	 * @return void
	 */
	public function onSleep() {
		if (!Daemon::$obInStack) { // preventing recursion
			@ob_flush();
		}
		unset($_GET);
		unset($_POST);
		unset($_COOKIE);
		unset($_REQUEST);
		unset($_SESSION);
		unset($_FILES);
		unset($_SERVER);
		parent::onSleep();
	}

	/**
	 * Send HTTP-status
	 * @throws RequestHeadersAlreadySent
	 * @param int Code
	 * @return void
	 */
	public function status($code = 200) {
		if (!isset(self::$codes[$code])) {
			return false;
		}

		$this->header($code . ' ' . self::$codes[$code]);

		return true;
	}

	/**
	 * Analog of standard PHP function headers_sent
	 * @return boolean Success
	 */
	public function headers_sent(&$file, &$line) {
		$file = $this->headers_sent_file;
		$line = $this->headers_sent_line;
		return $this->headers_sent;
	}

	/**
	 * Return current list of headers
	 * @return array Headers.
	 */
	public function headers_list() {
		return array_values($this->headers);
	}

	/**
	 * Set the cookie
	 * @param string Name of cookie
	 * @param string Value
	 * @param integer. Optional. Max-Age. Default is 0.
	 * @param string. Optional. Path. Default is empty string.
	 * @param boolean. Optional. Secure. Default is false.
	 * @param boolean. Optional. HTTPOnly. Default is false.
	 * @return void
	 * @throws RequestHeadersAlreadySent
	 */
	public function setcookie($name, $value = '', $maxage = 0, $path = '', $domain = '', $secure = false, $HTTPOnly = false) {
		$this->header(
			'Set-Cookie: ' . $name . '=' . rawurlencode($value)
			. (empty($domain) ? '' : '; Domain=' . $domain)
			. (empty($maxage) ? '' : '; Max-Age='.$maxage)
			. (empty($path) ? '' : '; Path='.$path)
			. (!$secure ? '' : '; Secure')
			. (!$HTTPOnly ? '' : '; HttpOnly'), false);
	}

	/**
	 * Send the header
	 * @param string Header. Example: 'Location: http://php.net/'
	 * @param boolean Optional. Replace?
	 * @param int Optional. HTTP response code.
	 * @return void
	 * @throws RequestHeadersAlreadySent
	 */
	public function header($s, $replace = true, $code = false) {
		if ($code !== null) {
			$this->status($code);
		}

		if ($this->headers_sent) {
			throw new RequestHeadersAlreadySent();
			return false;
		}

		$e = explode(':', $s, 2);

		if (!isset($e[1])) {
			$e[0] = 'STATUS';

			if (strncmp($s, 'HTTP/', 5) === 0) {
				$s = substr($s, 9);
			}
		}

		$k = strtr(strtoupper($e[0]), HTTPRequest::$htr);

		if ($k === 'CONTENT_TYPE') {
			self::parse_str(strtolower($e[1]), $ctype, true);
			if (!isset($ctype['charset'])) {
				$ctype['charset'] = $this->upstream->pool->config->defaultcharset->value;

				$s = $e[0].': ';
				$i = 0;
				foreach ($ctype as $k => $v) {
					$s .= ($i > 0 ? '; ' : '') . $k.  ($v !== '' ? '=' . $v : '');
					++$i;
				}
			}
		}

		if ($k === 'SET_COOKIE') {
			$k .= '_'.++$this->cookieNum;
		}
		elseif (
			!$replace
			&& isset($this->headers[$k])
		) {
			return false;
		}

		$this->headers[$k] = $s;

		if ($k === 'CONTENT_LENGTH') {
			$this->contentLength = (int) $e[1];
		}
		elseif ($k === 'LOCATION') {
			$this->status(301);
		}

		if (Daemon::$compatMode) {
			is_callable('header_native') ? header_native($s) : header($s);
		}

		return true;
	}

	/**
	 * @todo description missing
	 */
	public function parseSize($value) {
		$l = strtolower(substr($value, -1));

		if ($l === 'b') {
			return ((int) substr($value, 0, -1));
		}

		if ($l === 'k') {
			return ((int) substr($value, 0, -1) * 1024);
		}

		if ($l === 'm') {
			return ((int) substr($value, 0, -1) * 1024 * 1024);
		}

		if ($l === 'g') {
			return ((int) substr($value, 0, -1) * 1024 * 1024 * 1024);
		}
		return (int) $value;
	}

	/**
	 * Parse request body
	 * @return void
	 */
	public function parseStdin() {
		if (!isset($this->contype['multipart/form-data'])) {
			return;
		}
		start:
		if ($this->mpartstate === self::MPSTATE_SEEKBOUNDARY) {
			// seek to the nearest boundary
			if (($p = strpos($this->attrs->stdinbuf, $ndl = '--' . $this->boundary . "\r\n", $this->mpartoffset)) !== false) {
				// we have found the nearest boundary at position $p
				$this->mpartoffset = $p + strlen($ndl);
				$this->mpartstate = self::MPSTATE_HEADERS;
			} else {
				return;
			}
		}
		if ($this->mpartstate === self::MPSTATE_HEADERS) {
			// parse the part's headers
			$this->mpartcondisp = false;
			if (($p = strpos($this->attrs->stdinbuf, "\r\n\r\n", $this->mpartoffset)) !== false) {
				// we got all of the headers
				$h = explode("\r\n", binarySubstr($this->attrs->stdinbuf, $this->mpartoffset, $p - $this->mpartoffset));
				$this->mpartoffset = $p + 4;
				$this->attrs->stdinbuf = binarySubstr($this->attrs->stdinbuf, $this->mpartoffset);
				$this->mpartoffset = 0;
				for ($i = 0, $s = sizeof($h); $i < $s; ++$i) {
					$e = explode(':', $h[$i], 2);
					$e[0] = strtr(strtoupper($e[0]), HTTPRequest::$htr);

					if (isset($e[1])) {
						$e[1] = ltrim($e[1]);
					}
					if (
						($e[0] == 'CONTENT_DISPOSITION')
						&& isset($e[1])
					) {
						self::parse_str($e[1], $this->mpartcondisp, true);
						if (!isset($this->mpartcondisp['form-data'])) {
							break;
						}

						if (!isset($this->mpartcondisp['name'])) {
							break;
						}
						$this->mpartcondisp['name'] = trim($this->mpartcondisp['name'], '"');
						$name = $this->mpartcondisp['name'];
						if (isset($this->mpartcondisp['filename'])) {
							$this->mpartcondisp['filename'] = trim($this->mpartcondisp['filename'], '"');
							if (!ini_get('file_uploads')) {
								break;
							}
							$this->attrs->files[$name] = array(
								'name'     => $this->mpartcondisp['filename'],
								'type'     => '',
								'tmp_name' => '',
								'error'    => UPLOAD_ERR_OK,
								'size'     => 0,
							);
							$tmpdir = ini_get('upload_tmp_dir');
							if ($tmpdir === false) {
								$this->attrs->files[$name]['fp'] = false;
								$this->attrs->files[$name]['error'] = UPLOAD_ERR_NO_TMP_DIR;
							} else {
								$this->attrs->files[$name]['tmp_name'] = FS::tempnam($tmpdir, 'php');
								if ($this->oldFashionUploadFP) {
									$this->attrs->files[$name]['fp'] = fopen($this->attrs->files[$name]['tmp_name'], 'c+');
								} else {
									$req = $this;
									if (FS::$supported) {
										$this->upstream->lockRead();
									}
									FS::open($this->attrs->files[$name]['tmp_name'], 'c+', function ($fp) use ($req, $name) {
										if (!$fp) {
											$req->attrs->files[$name]['error'] = UPLOAD_ERR_CANT_WRITE;
										}
										$req->attrs->files[$name]['fp'] = $fp;
										if (FS::$supported) {
											$req->upstream->unlockRead();
											$req->stdin('');
										}
									});
								}
							}
							$this->mpartstate = self::MPSTATE_UPLOAD;
						} else {
							$this->attrs->post[$this->mpartcondisp['name']] = '';
						}
					}
					elseif (
						($e[0] == 'CONTENT_TYPE')
						&& isset($e[1])
					) {
						if (
							isset($this->mpartcondisp['name'])
							&& isset($this->mpartcondisp['filename'])
						) {
							$this->attrs->files[$this->mpartcondisp['name']]['type'] = $e[1];
						}
					}
				}
				if ($this->mpartstate === self::MPSTATE_HEADERS) {
					$this->mpartstate = self::MPSTATE_BODY;
				}

				if ($this->upstream->readLocked) {
					return;
				}

				goto start;
			}
		}
		if (
			($this->mpartstate === self::MPSTATE_BODY)
			|| ($this->mpartstate === self::MPSTATE_UPLOAD)
		) {
			 // process the body
			if (
				(($p = strpos($this->attrs->stdinbuf, $ndl = "\r\n--" . $this->boundary . "\r\n", $this->mpartoffset)) !== false)
				|| (($p = strpos($this->attrs->stdinbuf, $ndl = "\r\n--" . $this->boundary . "--\r\n", $this->mpartoffset)) !== false)
			) { // we have the whole Part in buffer
				$chunk = binarySubstr($this->attrs->stdinbuf, $this->mpartoffset, $p - $this->mpartoffset);
				if (
					($this->mpartstate === self::MPSTATE_BODY)
					&& isset($this->mpartcondisp['name'])
				) {
					$this->attrs->post[$this->mpartcondisp['name']] .= $chunk;
				}
				elseif (
					($this->mpartstate === self::MPSTATE_UPLOAD)
					&& isset($this->mpartcondisp['filename'])
				) {

					if (!isset($this->attrs->files[$this->mpartcondisp['name']]['fp'])) {
						return; // fd is not ready yet, interrupt
					}

					if ($fp = $this->attrs->files[$this->mpartcondisp['name']]['fp']) {
						if ($this->oldFashionUploadFP) {
							fwrite($fp, $chunk);
						} else {
							$fp->write($chunk);
						}
					}

					$this->attrs->files[$this->mpartcondisp['name']]['size'] += strlen($chunk);
				}
				if ($ndl === "\r\n--" . $this->boundary . "--\r\n") { // end of whole message
					$this->mpartoffset = $p + strlen($ndl);
					$this->mpartstate = self::MPSTATE_SEEKBOUNDARY;
					$this->stdin_done = true;
				} else {
					$this->mpartoffset = $p;
					$this->mpartstate = self::MPSTATE_HEADERS; // let's read the next part
					goto start;
				}

				$this->attrs->stdinbuf = binarySubstr($this->attrs->stdinbuf, $this->mpartoffset);
				$this->mpartoffset = 0;
			} else { // we have only piece of Part in buffer
				$p = strlen($this->attrs->stdinbuf) - strlen($ndl);
				if ($p !== false) {
					$chunk = binarySubstr($this->attrs->stdinbuf, $this->mpartoffset, $p - $this->mpartoffset);
					if (
						($this->mpartstate === self::MPSTATE_BODY)
						&& isset($this->mpartcondisp['name'])
					) {
						$this->attrs->post[$this->mpartcondisp['name']] .= $chunk;
					}
					elseif (
						($this->mpartstate === self::MPSTATE_UPLOAD)
						&& isset($this->mpartcondisp['filename'])
					) {
						if (!isset($this->attrs->files[$this->mpartcondisp['name']]['fp'])) {
							return; // fd is not ready yet, interrupt
						}
						if ($fp = $this->attrs->files[$this->mpartcondisp['name']]['fp']) {
							if ($this->oldFashionUploadFP) {
								fwrite($fp, $chunk);
							} else {
								$fp->write($chunk);
							}
						}
						$this->attrs->files[$this->mpartcondisp['name']]['size'] += $p - $this->mpartoffset;

						if ($this->parseSize(ini_get('upload_max_filesize')) < $this->attrs->files[$this->mpartcondisp['name']]['size']) {
							$this->attrs->files[$this->mpartcondisp['name']]['error'] = UPLOAD_ERR_INI_SIZE;
						}

						if (
							isset($this->attrs->post['MAX_FILE_SIZE'])
							&& ($this->attrs->post['MAX_FILE_SIZE'] < $this->attrs->files[$this->mpartcondisp['name']]['size'])
						) {
							$this->attrs->files[$this->mpartcondisp['name']]['error'] = UPLOAD_ERR_FORM_SIZE;
						}
					}

					$this->mpartoffset = $p;
					$this->attrs->stdinbuf = binarySubstr($this->attrs->stdinbuf, $this->mpartoffset);
					$this->mpartoffset = 0;
				}
			}
		}
	}


	/**
	 * Tells whether the file was uploaded via HTTP POST
	 * @param string The filename being checked.
	 * @return boolean Whether if this is uploaded file.
	 */
	public function isUploadedFile($path) {
		$path = realpath($path);
		if (!$path) {
			return false;
		}
		if (strpos($path, ini_get('upload_tmp_dir') . '/') !== 0) {
			return false;
		}
		foreach ($this->attrs->files as $file) {
			if ($file['tmp_name'] === $path) {
				return true;
			}
		}
		return false;
	 }

	/**
	 *  Moves an uploaded file to a new location
	 * @param string The filename of the uploaded file.
	 * @param string The destination of the moved file.
	 * @return boolean Success.
	 */
	public function moveUploadedFile($filename,$dest) {
		if (!$this->isUploadedFile($filename)) {
			return false;
		}
		return rename($filename, $dest);
	 }

	/**
	 * Read request body from the file given in REQUEST_BODY_FILE parameter.
	 * @return void
	 */
	public function readBodyFile() {
		if (!isset($this->attrs->server['REQUEST_BODY_FILE'])) {
			return false;
		}
		FS::readfileChunked($this->attrs->server['REQUEST_BODY_FILE'],
			function ($file, $success) {
				$this->attrs->stdin_done = true;
				if ($this->sleepTime === 0) {
					$this->wakeup();
				}
			},
			function($file, $chunk) { // readed chunk
				$this->stdin($chunk);
			}
		);
	}

	/**
	 * Replacement for default parse_str(), it supoorts UCS-2 like this: %uXXXX
	 * @param string String to parse.
	 * @param array Reference to the resulting array.
	 * @param boolean Header-style string.
	 * @return void
	 */
	public static function parse_str($s, &$var, $header = false) {
		static $cb;
		if ($cb === NULL) {
			$cb = function ($m) {
				return urlencode(html_entity_decode('&#' . hexdec($m[1]) . ';', ENT_NOQUOTES, 'utf-8'));
			};
		}
		if ($header) {
			$s = strtr($s, HTTPRequest::$hvaltr);
		}
		if (
			(stripos($s,'%u') !== false)
			&& preg_match('~(%u[a-f\d]{4}|%[c-f][a-f\d](?!%[89a-f][a-f\d]))~is', $s, $m)
		) {
			$s = preg_replace_callback('~%(u[a-f\d]{4}|[a-f\d]{2})~i', $cb, $s);
		}
		parse_str($s, $var);
	}

	/**
	 * @todo description missing
	 * @todo protected?
	 */
	public function postFinishHandler() {
		if (!$this->headers_sent) {
			$this->out('');
		}
		$this->sendfp = null;
		if (isset($this->attrs->files)) {
			foreach ($this->attrs->files as &$f) {
				if (
					($f['error'] === UPLOAD_ERR_OK)
					&& file_exists($f['tmp_name'])
				) {
					FS::unlink($f['tmp_name']);
				}
			}
		}
		if (isset($this->attrs->session)) {
			$this->sessionCommit();
		}
	}

	public function sessionCommit() {
		session_commit();
	}

}
