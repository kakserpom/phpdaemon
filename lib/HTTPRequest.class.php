<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class HTTPRequest
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description HTTP request class.
/**************************************************************************/

class HTTPRequest extends Request {

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

	public $answerlen = 0;
	public $contentLength;
	
	public static $hvaltr = array(';' => '&', ' ' => '');
	public static $htr = array('-' => '_');
	
	public $mpartstate = 0;
	public $mpartoffset = 0;
	public $mpartcondisp = FALSE;
	public $headers = array('STATUS' => '200 OK');
	public $headers_sent = FALSE; // FIXME: move to httprequest and make private
	private $boundary = FALSE;
		
	/**
	 * @method preint
	 * @description Preparing before init.
	 * @param object Source request.
	 * @return void
	 */
	public function preinit($req)
	{
		if ($req === NULL) {
			$req = clone Daemon::$dummyRequest;
		}
		
		$this->attrs = $req->attrs;

		if (
			isset($this->upstream->expose->value) 
			&& $this->upstream->expose->value
		) {
			$this->header('X-Powered-By: phpDaemon/' . Daemon::$version);
		}

		$this->parseParams();
	}
	
	/**
	 * @method parseParams
	 * @description Parses GET-query string and other request's headers.  
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
			$this->parse_str($this->attrs->server['QUERY_STRING'], $this->attrs->get);
		}

		if (
			isset($this->attrs->server['REQUEST_METHOD']) 
			&& ($this->attrs->server['REQUEST_METHOD'] == 'POST') 
			&& isset($this->attrs->server['HTTP_CONTENT_TYPE'])
		) {
			parse_str(strtr($this->attrs->server['HTTP_CONTENT_TYPE'], HTTPRequest::$hvaltr), $contype);

			if (
				isset($contype['multipart/form-data']) 
				&& (isset($contype['boundary']))
			) {
				$this->boundary = $contype['boundary'];
			}
		}

		if (isset($this->attrs->server['HTTP_COOKIE'])) {
			$this->parse_str(strtr($this->attrs->server['HTTP_COOKIE'], HTTPRequest::$hvaltr), $this->attrs->cookie);
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
	 * @method postPrepare
	 * @description Prepares the request's body.
	 * @return void
	 */
	public function postPrepare() {
		if (
			isset($this->attrs->server['REQUEST_METHOD']) 
			&& ($this->attrs->server['REQUEST_METHOD'] == 'POST')
		) {
			if ($this->boundary === FALSE) {
				$this->parse_str($this->attrs->stdinbuf, $this->attrs->post);
			}

			if (
				isset($this->attrs->server['REQUEST_BODY_FILE']) 
				&& isset($this->upstream->config->autoreadbodyfile->value)
				&& $this->upstream->config->autoreadbodyfile->value
			) {
				$this->readBodyFile();
			}
		}
	}

	/**
	 * @method stdin
	 * @param string Piece of request's body.
	 * @description Called when new piece of request's body is received.
	 * @return void
	 */
	public function stdin($c) {
		if ($c !== '') {
			$this->attrs->stdinbuf .= $c;
			$this->attrs->stdinlen += strlen($c);
		}

		if (
			!isset($this->attrs->server['HTTP_CONTENT_LENGTH']) 
			|| ($this->attrs->server['HTTP_CONTENT_LENGTH'] <= $this->attrs->stdinlen)
		) {
			$this->attrs->stdin_done = TRUE;
			$this->postPrepare();
		}

		$this->parseStdin();
	}

	/**
	 * @method out
	 * @param string String to out.
	 * @description Outputs data.
	 * @return boolean Success.
	 */
	public function out($s, $flush = TRUE) {
		if ($flush) {
			ob_flush();
		}

		if ($this->aborted) {
			return FALSE;
		}

		$l = strlen($s);
		$this->answerlen += $l;

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
			$this->headers_sent = TRUE;

			if (!Daemon::$compatMode) {
				if (
					!$this->attrs->chunked 
					&& !$this->sendfp
				) {
					return $this->upstream->requestOut($this, $h . $s);
				}

				$this->upstream->requestOut($this,$h);
			}
		}

		if ($this->attrs->chunked) {
			for ($o = 0; $o < $l;) {
				$c = min($this->upstream->chunksize->value, $l - $o);

				$chunk = dechex($c) . "\r\n"
					. ($c === $l ? $s : binarySubstr($s, $o, $c)) // content
					. "\r\n";

				if ($this->sendfp) {
					fwrite($this->sendfp, $chunk);
				} else {
					$this->upstream->requestOut($this, $chunk);
				}

				$o += $c;
			}
		} else {
			if ($this->sendfp) {
				fwrite($this->sendfp, $s);
				return TRUE;
			}

			if (Daemon::$compatMode) {
				echo $s;
				return TRUE;
			}

			return $this->upstream->requestOut($this, $s);
		}
	}

	/**
	 * @method onParsedParams
	 * @description Called when request's headers parsed.
	 * @return void
	 */
	public function onParsedParams() {}

	/**
	 * @method combinedOut
	 * @param string String to out.
	 * @description Outputs data with headers (split by \r\n\r\n)
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

			return TRUE;
		} else {
			return $this->out($s);
		}
	}

	/**
	 * @method chunked
	 * @description Use chunked encoding.
	 * @return void
	 */
	public function chunked() {
		$this->header('Transfer-Encoding: chunked');
		$this->attrs->chunked = TRUE;
	}
	
	/**
	 * @method onWakeUp
	 * @description Called when the request wakes up.
	 * @return void
	 */
	protected function onWakeup() {
		parent::onWakeup();
	
		$_GET     = &$this->attrs->get;
		$_POST    = &$this->attrs->post;
		$_COOKIE  = &$this->attrs->cookie;
		$_REQUEST = &$this->attrs->request;
		$_SESSION = &$this->attrs->session;
		$_FILES   = &$this->attrs->files;
		$_SERVER  = &$this->attrs->server;
	}

	/**
	 * @method status
	 * @throws RequestHeadersAlreadySent
	 * @param int Code
	 * @description Sends HTTP-status (200, 403, 404, 500, etc)
	 * @return void
	 */
	public function status($code = 200) {
		if (!isset(self::$codes[$code])) {
			return FALSE;
		}

		$this->header($code . ' ' . self::$codes[$code]);

		return TRUE;
	}

	/**
	 * @method headers_sent
	 * @description Analog of standard PHP function headers_sent
	 * @return boolean Success
	 */
	public function headers_sent() {
		return $this->headers_sent;
	}

	/**
	 * @method headers_list
	 * @description Returns current list of headers.
	 * @return array Headers.
	 */
	public function headers_list() {
		return array_values($this->headers);
	}

	/**
	 * @method setcookie
	 * @description Sets the cookie.
	 * @param string Name of cookie.
	 * @param string Value.
	 * @param integer. Optional. Max-Age. Default is 0.
	 * @param string. Optional. Path. Default is empty string.
	 * @param boolean. Optional. Secure. Default is false.
	 * @param boolean. Optional. HTTPOnly. Default is false.
	 * @return void
	 * @throws RequestHeadersAlreadySent
	 */
	public function setcookie($name, $value = '', $maxage = 0, $path = '', $domain = '', $secure = FALSE, $HTTPOnly = FALSE) {
		$this->header(
			'Set-Cookie: ' . $name . '=' . rawurlencode($value) 
			. (empty($domain) ? '' : '; Domain=' . $domain) 
			. (empty($maxage) ? '' : '; Max-Age='.$maxage) 
			. (empty($path) ? '' : '; Path='.$path) 
			. (!$secure ? '' : '; Secure') 
			. (!$HTTPOnly ? '' : '; HttpOnly'), false); 
	}

	/**
	 * @method header
	 * @description Sets the header.
	 * @param string Header. Example: 'Location: http://php.net/'
	 * @param boolean Optional. Replace?
	 * @param int Optional. HTTP response code.
	 * @return void
	 * @throws RequestHeadersAlreadySent
	 */
	public function header($s, $replace = TRUE, $code = NULL) {
		if ($code !== NULL) {
			$this->status($code);
		}

		if ($this->headers_sent) {
			throw new RequestHeadersAlreadySent();
			return FALSE;
		}

		$e = explode(':', $s, 2);

		if (!isset($e[1])) {
			$e[0] = 'STATUS';

			if (strncmp($s, 'HTTP/', 5) === 0) {
				$s = substr($s, 9);
			}
		}

		$k = strtr(strtoupper($e[0]), HTTPRequest::$htr);

		if (
			!$replace 
			&& isset($this->headers[$k])
		) {
			return FALSE;
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

		return TRUE;
	}
	
	/**
	 * @method parseStdin
	 * @description Parses request's body.
	 * @return void
	 */
	public function parseStdin() {
		do {
			if ($this->boundary === FALSE) {
				break;
			}

			$continue = FALSE;

			if ($this->mpartstate === 0) {
				// seek to the nearest boundary
				if (($p = strpos($this->attrs->stdinbuf, $ndl = '--' . $this->boundary . "\r\n", $this->mpartoffset)) !== FALSE) {
					// we have found the nearest boundary at position $p
					$this->mpartoffset = $p + strlen($ndl);
					$this->mpartstate = 1;
					$continue = TRUE;
				}
			}
			elseif ($this->mpartstate === 1) {
				// parse the part's headers
				$this->mpartcondisp = FALSE;

				if (($p = strpos($this->attrs->stdinbuf, "\r\n\r\n", $this->mpartoffset)) !== FALSE) {
					// we got all of the headers
					$h = explode("\r\n", binarySubstr($this->attrs->stdinbuf, $this->mpartoffset, $p-$this->mpartoffset));
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
							parse_str(strtr($e[1], HTTPRequest::$hvaltr), $this->mpartcondisp);

							if (!isset($this->mpartcondisp['form-data'])) {
								break;
							}

							if (!isset($this->mpartcondisp['name'])) {
								break;
							}

							$this->mpartcondisp['name'] = trim($this->mpartcondisp['name'], '"');

							if (isset($this->mpartcondisp['filename'])) {
								$this->mpartcondisp['filename'] = trim($this->mpartcondisp['filename'], '"');

								if (!ini_get('file_uploads')) {
									break;
								}

								$this->attrs->files[$this->mpartcondisp['name']] = array(
									'name'     => $this->mpartcondisp['filename'],
									'type'     => '',
									'tmp_name' => '',
									'error'    => UPLOAD_ERR_OK,
									'size'     => 0,
								);

								$tmpdir = ini_get('upload_tmp_dir');

								if ($tmpdir === FALSE) {
									$this->attrs->files[$this->mpartcondisp['name']]['fp'] = FALSE;
									$this->attrs->files[$this->mpartcondisp['name']]['error'] = UPLOAD_ERR_NO_TMP_DIR;
								} else {
									$this->attrs->files[$this->mpartcondisp['name']]['fp'] = @fopen($this->attrs->files[$this->mpartcondisp['name']]['tmp_name'] = tempnam($tmpdir, 'php'), 'w');

									if (!$this->attrs->files[$this->mpartcondisp['name']]['fp']) {
										$this->attrs->files[$this->mpartcondisp['name']]['error'] = UPLOAD_ERR_CANT_WRITE;
									}
								}

								$this->mpartstate = 3;
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

					if ($this->mpartstate === 1) {
						$this->mpartstate = 2;
					}

					$continue = TRUE;
				}
			}
			elseif (
				($this->mpartstate === 2) 
				|| ($this->mpartstate === 3)
			) {
				 // process the body
				if (
					(($p = strpos($this->attrs->stdinbuf, $ndl = "\r\n--" . $this->boundary . "\r\n", $this->mpartoffset)) !== FALSE)
					|| (($p = strpos($this->attrs->stdinbuf, $ndl = "\r\n--" . $this->boundary . "--\r\n", $this->mpartoffset)) !== FALSE)
				) {
					if (
						($this->mpartstate === 2) 
						&& isset($this->mpartcondisp['name'])
					) {
						$this->attrs->post[$this->mpartcondisp['name']] .= binarySubstr($this->attrs->stdinbuf, $this->mpartoffset, $p-$this->mpartoffset);
					}
					elseif (
						($this->mpartstate === 3) 
						&& isset($this->mpartcondisp['filename'])
					) {
						if ($this->attrs->files[$this->mpartcondisp['name']]['fp']) {
							fwrite($this->attrs->files[$this->mpartcondisp['name']]['fp'], binarySubstr($this->attrs->stdinbuf, $this->mpartoffset, $p-$this->mpartoffset));
						}

						$this->attrs->files[$this->mpartcondisp['name']]['size'] += $p-$this->mpartoffset;
					}

					if ($ndl === "\r\n--" . $this->boundary . "--\r\n") {
						$this->mpartoffset = $p+strlen($ndl);
						$this->mpartstate = 0; // we done at all
					} else {
						$this->mpartoffset = $p;
						$this->mpartstate = 1; // let us parse the next part
						$continue = TRUE;
					}

					$this->attrs->stdinbuf = binarySubstr($this->attrs->stdinbuf, $this->mpartoffset);
					$this->mpartoffset = 0;
				} else {
					$p = strrpos($this->attrs->stdinbuf, "\r\n", $this->mpartoffset);

					if ($p !== FALSE) {
						if (
							($this->mpartstate === 2) 
							&& isset($this->mpartcondisp['name'])
						) {
							$this->attrs->post[$this->mpartcondisp['name']] .= binarySubstr($this->attrs->stdinbuf, $this->mpartoffset, $p - $this->mpartoffset);
						}
						elseif (
							($this->mpartstate === 3) 
							&& isset($this->mpartcondisp['filename'])
						) {
							if ($this->attrs->files[$this->mpartcondisp['name']]['fp']) {
								fwrite($this->attrs->files[$this->mpartcondisp['name']]['fp'], binarySubstr($this->attrs->stdinbuf, $this->mpartoffset, $p - $this->mpartoffset));
							}

							$this->attrs->files[$this->mpartcondisp['name']]['size'] += $p - $this->mpartoffset;

							if (Daemon::parseSize(ini_get('upload_max_filesize')) < $this->attrs->files[$this->mpartcondisp['name']]['size']) {
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
		} while ($continue);
	}

	/**
	 * @method readBodyFile
	 * @description Reads request's body from file.
	 * @return void
	 */
	public function readBodyFile() {
		if (!isset($this->attrs->server['REQUEST_BODY_FILE'])) {
			return FALSE;
		}

		$fp = fopen($this->attrs->server['REQUEST_BODY_FILE'], 'rb');

		if (!$fp) {
			Daemon::log('Couldn\'t open request-body file \'' . $this->attrs->server['REQUEST_BODY_FILE'] . '\' (REQUEST_BODY_FILE).');
			return FALSE;
		}

		while (!feof($fp)) {
			$this->stdin($this->fread($fp, 4096));
		}

		fclose($fp);
		$this->attrs->stdin_done = TRUE;
	}

	/**
	 * @method parse_str
	 * @param string String to parse.
	 * @param array Reference to the resulting array.
	 * @description Replacement for default parse_str(), it supoorts UCS-2 like this: %uXXXX.
	 * @return void
	 */
	public function parse_str($s, &$array) {
		if (
			(stripos($s,'%u') !== FALSE) 
			&& preg_match('~(%u[a-f\d]{4}|%[c-f][a-f\d](?!%[89a-f][a-f\d]))~is', $s, $m)
		) {
			$s = preg_replace_callback('~%(u[a-f\d]{4}|[a-f\d]{2})~i', array($this, 'parse_str_callback'), $s);
		}

		parse_str($s, $array);
	}

	/**
	 * @method parse_str_callback
	 * @param array Match.
	 * @description Called in preg_replace_callback in parse_str.
	 * @return string Replacement.
	 */
	public function parse_str_callback($m) {
		return urlencode(html_entity_decode('&#' . hexdec($m[1]) . ';', ENT_NOQUOTES, 'utf-8'));
	}
}
