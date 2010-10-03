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

		$k = strtr(strtoupper($e[0]), Request::$htr);

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

}
