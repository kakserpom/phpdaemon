<?php
namespace PHPDaemon\HTTPRequest;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\FS\File;
use PHPDaemon\FS\FileSystem;
use PHPDaemon\Request\RequestHeadersAlreadySent;
use PHPDaemon\Utils\MIME;
use PHPDaemon\Traits\DeferredEventHandlers;
use PHPDaemon\Traits\EventHandlers;
use PHPDaemon\Traits\StaticObjectWatchdog;
use PHPDaemon\Traits\ClassWatchdog;

/**
 * HTTP request
 * @package PHPDaemon\HTTPRequest
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
abstract class Generic extends \PHPDaemon\Request\Generic {
	use DeferredEventHandlers;
	use \PHPDaemon\Traits\Sessions;

	/**
	 * @var array Status codes
	 */
	protected static $codes = [
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
		422 => 'Unprocessable Entity',
		423 => 'Locked',
		500 => 'Internal Server Error',
		501 => 'Not Implemented',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
		504 => 'Gateway Timeout',
		505 => 'HTTP Version Not Supported',
	];

	/**
	 * @var boolean Keepalive?
	 */
	public $keepalive = false;

	/**
	 * @var integer Current response length
	 */
	public $responseLength = 0;

	/**
	 * @var integer Content length from header() method
	 */
	protected $contentLength;

	/**
	 * @var integer Number of outgoing cookie-headers
	 */
	protected $cookieNum = 0;

	/**
	 * @var array Replacement pairs for processing some header values in parse_str()
	 */
	public static $hvaltr = ['; ' => '&', ';' => '&', ' ' => '%20'];

	/**
	 * @var array State
	 */
	public static $htr = ['-' => '_'];

	/**
	 * @var array Outgoing headers
	 */
	protected $headers = ['STATUS' => '200 OK'];

	/**
	 * @var boolean Headers sent?
	 */
	protected $headers_sent = false;

	/**
	 * @var boolean File name where output started in the file and line variables
	 */
	protected $headers_sent_file;

	/**
	 * @var boolean Line number where output started in the file and line variables
	 */
	protected $headers_sent_line;

	/**
	 * @var File File pointer to send output (X-Sendfile)
	 */
	protected $sendfp;

	/**
	 * @var boolean Frozen input?
	 */
	protected $frozenInput = false;

	/**
	 * @var array Content type parameters
	 */
	protected $contype;

	/**
	 * Preparing before init
	 * @param  object $req Source request
	 * @return void
	 */
	protected function preinit($req) {
		if ($req === null) {
			$req                    = new \stdClass;
			$req->attrs             = new \stdClass;
			$req->attrs->inputDone  = true;
			$req->attrs->paramsDone = true;
			$req->attrs->chunked    = false;
		}

		$this->attrs = $req->attrs;

		if ($this->upstream->pool->config->expose->value) {
			$this->header('X-Powered-By: phpDaemon/' . Daemon::$version);
		}

		$this->attrs->input->setRequest($this);

		$this->parseParams();
	}

	/**
	 * Called when first deferred event used
	 * @return void
	 */
	public function firstDeferredEventUsed () {
		$this->bind('finish', [$this, 'cleanupDeferredEventHandlers']);
	}

	/**
	 * Output whole contents of file
	 * @param  string   $path Path
	 * @param  callable $cb   Callback
	 * @param  integer  $pri  Priority
	 * @return boolean        Success
	 */
	public function sendfile($path, $cb, $pri = EIO_PRI_DEFAULT) {
		if ($this->state === self::STATE_FINISHED) {
			return false;
		}
		try {
			$this->header('Content-Type: ' . MIME::get($path));
		} catch (RequestHeadersAlreadySent $e) {
		}
		if ($this->upstream->checkSendfileCap()) {
			FileSystem::sendfile($this->upstream, $path, $cb, function ($file, $length, $handler) {
				try {
					$this->header('Content-Length: ' . $length);
				} catch (RequestHeadersAlreadySent $e) {
				}
				$this->ensureSentHeaders();
				$this->upstream->onWriteOnce(function ($conn) use ($handler, $file) {
					$handler($file);
				});
				return true;
			}, 0, null, $pri);
			return true;
		}
		$first = true;
		FileSystem::readfileChunked($path, $cb, function ($file, $chunk) use (&$first) { // readed chunk
			if ($this->upstream->isFreed()) {
				return false;
			}
			if ($first) {
				try {
					$this->header('Content-Length: ' . $file->stat['size']);
				} catch (RequestHeadersAlreadySent $e) {
				}
				$first = false;
			}
			$this->out($chunk);
			return true;
		});
		return true;
	}

	/**
	 * Get cookie by name
	 * @param  string $name Name of cookie
	 * @return string       Contents
	 */
	protected function getCookieStr($name) {
		return static::getString($this->attrs->cookie[$name]);
	}

	/**
	 * Called to check if Request is ready
	 * @return boolean Ready?
	 */
	public function checkIfReady() {
		if (!$this->attrs->paramsDone || !$this->attrs->inputDone) {
			return false;
		}
		if (isset($this->appInstance->passphrase)) {
			if (
					!isset($this->attrs->server['PASSPHRASE'])
					|| ($this->appInstance->passphrase !== $this->attrs->server['PASSPHRASE'])
			) {
				$this->finish();
			}
			return false;
		}
		if ($this->attrs->input->isFrozen()) {
			return false;
		}
		if ($this->sleepTime === 0) {
			$this->wakeup();
		}
		return true;
	}

	/**
	 * Upload maximum file size
	 * @return integer
	 */
	public function getUploadMaxSize() {
		return $this->upstream->pool->config->uploadmaxsize->value;
	}

	/**
	 * Parses GET-query string and other request's headers
	 * @return void
	 */
	protected function parseParams() {
		if (!isset($this->attrs->server['HTTP_CONTENT_LENGTH'])) {
			$this->attrs->contentLength = 0;
		}
		else {
			$this->attrs->contentLength = (int)$this->attrs->server['HTTP_CONTENT_LENGTH'];
		}
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
				&& ($this->attrs->server['REQUEST_METHOD'] === 'POST' || $this->attrs->server['REQUEST_METHOD'] === 'PUT')
				&& isset($this->attrs->server['HTTP_CONTENT_TYPE'])
		) {
			$this->attrs->server['REQUEST_METHOD_POST'] = true;
			self::parse_str($this->attrs->server['HTTP_CONTENT_TYPE'], $this->contype, true);
			$found = false;
			foreach ($this->contype as $k => $v) {
				if (strpos($k, '/') === false) {
					continue;
				}
				if (!$found) {
					$found = true;
				}
				else {
					unset($this->contype[$k]);
				}
			}

			if (isset($this->contype['multipart/form-data'])
					&& (isset($this->contype['boundary']))
			) {
				$this->attrs->input->setBoundary($this->contype['boundary']);
			}
		}
		else {
			$this->attrs->server['REQUEST_METHOD_POST'] = false;
		}

		if (isset($this->attrs->server['HTTP_COOKIE'])) {
			self::parse_str($this->attrs->server['HTTP_COOKIE'], $this->attrs->cookie, true);
		}

		if (isset($this->attrs->server['HTTP_AUTHORIZATION'])) {
			$e = explode(' ', $this->attrs->server['HTTP_AUTHORIZATION'], 2);

			if (
					($e[0] === 'Basic')
					&& isset($e[1])
			) {
				$e[1] = base64_decode($e[1]);
				$e    = explode(':', $e[1], 2);

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
		if (!$this->attrs->server['REQUEST_METHOD_POST']) {
			return;
		}
		if (isset($this->attrs->server['REQUEST_PREPARED_UPLOADS']) && $this->attrs->server['REQUEST_PREPARED_UPLOADS'] === 'nginx') {
			if (isset($this->attrs->server['REQUEST_PREPARED_UPLOADS_URL_PREFIX'])) {
				$URLprefix = $this->attrs->server['REQUEST_PREPARED_UPLOADS_URL_PREFIX'];
				$l         = strlen($URLprefix);
				foreach (['PHP_SELF', 'REQUEST_URI', 'SCRIPT_NAME', 'DOCUMENT_URI'] as $k) {
					if (!isset($this->attrs->server[$k])) {
						continue;
					}
					if (strncmp($this->attrs->server[$k], $URLprefix, $l) === 0) {
						$this->attrs->server[$k] = substr($this->attrs->server[$k], $l - 1);
					}
				}
			}
			$prefix    = 'file.';
			$prefixlen = strlen($prefix);
			foreach ($this->attrs->post as $k => $v) {
				if (strncmp($k, $prefix, $prefixlen) === 0) {
					$e = explode('.', substr($k, $prefixlen));
					if (!isset($e[1])) {
						$e = ['file', $e[0]];
					}
					if (!isset($this->attrs->files[$e[0]])) {
						$this->attrs->files[$e[0]] = ['error' => UPLOAD_ERR_OK];
					}
					$this->attrs->files[$e[0]][$e[1]] = $v;
					unset($this->attrs->post[$k]);
				}
			}
			$uploadTmp = $this->getUploadTempDir();
			foreach ($this->attrs->files as $k => &$file) {
				if (!isset($file['tmp_name'])
						|| !isset($file['name'])
						|| !ctype_digit(basename($file['tmp_name']))
						|| (strpos(pathinfo($file['tmp_name'], PATHINFO_DIRNAME), $uploadTmp) !== 0)
				) {
					unset($this->attrs->files[$k]);
					continue;
				}
				FileSystem::open($file['tmp_name'], 'c+!', function ($fp) use (&$file) {
					if (!$fp) {
						return;
					}
					$file['fp'] = $fp;
				});
			}
			unset($file);
		}
		if (isset($this->attrs->server['REQUEST_BODY_FILE'])
				&& $this->upstream->pool->config->autoreadbodyfile->value
		) {
			$this->readBodyFile();
		}
	}

	/**
	 * Ensure that headers are sent
	 * @return boolean Were already sent?
	 */
	public function ensureSentHeaders() {
		if ($this->headers_sent) {
			return true;
		}
		if (isset($this->headers['STATUS'])) {
			$h = (isset($this->attrs->noHttpVer) && ($this->attrs->noHttpVer) ? 'Status: ' : $this->attrs->server['SERVER_PROTOCOL']) . ' ' . $this->headers['STATUS'] . "\r\n";
		}
		else {
			$h = '';
		}
		$http11 = $this->attrs->server['SERVER_PROTOCOL'] === 'HTTP/1.1';
		if ($this->contentLength === null
			&& $this->upstream->checkChunkedEncCap()
			&& $http11) {
			$this->attrs->chunked = true;
		}
		if ($this->attrs->chunked) {
			$this->header('Transfer-Encoding: chunked');
		}

		if ($http11) {
			$connection = isset($this->attrs->server['HTTP_CONNECTION']) ? strtolower($this->attrs->server['HTTP_CONNECTION']) : 'keep-alive';
			if ($connection === 'keep-alive' && $this->upstream->getKeepaliveTimeout() > 0) {
				$this->header('Connection: keep-alive');	
				$this->keepalive = true;
			} else {
				$this->header('Connection: close');
			}
		} else {
			$this->header('Connection: close');
		}

		foreach ($this->headers as $k => $line) {
			if ($k !== 'STATUS') {
				$h .= $line . "\r\n";
			}
		}
		$h .= "\r\n";
		$this->headers_sent_file = __FILE__;
		$this->headers_sent_line = __LINE__;
		$this->headers_sent      = true;
		$this->upstream->requestOut($this, $h);
		return false;
	}

	/**
	 * Output some data
	 * @param  string  $s     String to out
	 * @param  boolean $flush ob_flush?
	 * @return boolean        Success
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
		$this->responseLength += $l;

		$this->ensureSentHeaders();

		if ($this->attrs->chunked) {
			for ($o = 0; $o < $l;) {
				$c = min($this->upstream->pool->config->chunksize->value, $l - $o);

				$chunk = dechex($c) . "\r\n"
						. ($c === $l ? $s : binarySubstr($s, $o, $c)) // content
						. "\r\n";

				if ($this->sendfp) {
					$this->sendfp->write($chunk);
				}
				else {
					$this->upstream->requestOut($this, $chunk);
				}

				$o += $c;
			}
			return true;
		}
		else {
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
	public function onParsedParams() {
	}

	/**
	 * Outputs data with headers (split by \r\n\r\n)
	 * @param  string  $s Data
	 * @return boolean    Success
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
		}
		else {
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
		$_GET     = & $this->attrs->get;
		$_POST    = & $this->attrs->post;
		$_COOKIE  = & $this->attrs->cookie;
		$_REQUEST = & $this->attrs->request;
		$_SESSION = & $this->attrs->session;
		$_FILES   = & $this->attrs->files;
		$_SERVER  = & $this->attrs->server;
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
	 * @param  integer $code Code
	 * @throws RequestHeadersAlreadySent
	 * @return boolean Success
	 */
	public function status($code = 200) {
		if (!isset(self::$codes[$code])) {
			return false;
		}
		$this->header($code . ' ' . self::$codes[$code]);
		return true;
	}

	/**
	 * Checks if headers have been sent
	 * @param  string  &$file File name
	 * @param  integer &$line Line in file
	 * @return boolean        Success
	 */
	public function headers_sent(&$file, &$line) {
		$file = $this->headers_sent_file;
		$line = $this->headers_sent_line;
		return $this->headers_sent;
	}

	/**
	 * Return current list of headers
	 * @return array Headers
	 */
	public function headers_list() {
		return array_values($this->headers);
	}

	/**
	 * Set the cookie
	 * @param string  $name     Name of cookie
	 * @param string  $value    Value
	 * @param integer $maxage   Optional. Max-Age. Default is 0
	 * @param string  $path     Optional. Path. Default is empty string
	 * @param string  $domain   Optional. Domain. Default is empty string
	 * @param boolean $secure   Optional. Secure. Default is false
	 * @param boolean $HTTPOnly Optional. HTTPOnly. Default is false
	 * @return void
	 */
	public function setcookie($name, $value = '', $maxage = 0, $path = '', $domain = '', $secure = false, $HTTPOnly = false) {
		$this->header(
			'Set-Cookie: ' . $name . '=' . rawurlencode($value)
			. (empty($domain) ? '' : '; Domain=' . $domain)
			. (empty($maxage) ? '' : '; Max-Age=' . $maxage)
			. (empty($path) ? '' : '; Path=' . $path)
			. (!$secure ? '' : '; Secure')
			. (!$HTTPOnly ? '' : '; HttpOnly'), false);
	}

	/**
	 * Send the header
	 * @param  string  $s       Header. Example: 'Location: http://php.net/'
	 * @param  boolean $replace Optional. Replace?
	 * @param  integer $code    Optional. HTTP response code
	 * @throws \PHPDaemon\Request\RequestHeadersAlreadySent
	 * @return boolean Success
	 */
	public function header($s, $replace = true, $code = false) {
		if (!$code) {
			$this->status($code);
		}

		if ($this->headers_sent) {
			throw new RequestHeadersAlreadySent;
		}
		$s = strtr($s, "\r\n", '  ');

		$e = explode(':', $s, 2);

		if (!isset($e[1])) {
			$e[0] = 'STATUS';

			if (strncmp($s, 'HTTP/', 5) === 0) {
				$s = substr($s, 9);
			}
		}

		$k = strtr(strtoupper($e[0]), Generic::$htr);

		if ($k === 'CONTENT_TYPE') {
			self::parse_str(strtolower($e[1]), $ctype, true);
			if (!isset($ctype['charset'])) {
				$ctype['charset'] = $this->upstream->pool->config->defaultcharset->value;

				$s = $e[0] . ': ';
				$i = 0;
				foreach ($ctype as $k => $v) {
					$s .= ($i > 0 ? '; ' : '') . $k . ($v !== '' ? '=' . $v : '');
					++$i;
				}
			}
		}

		if ($k === 'SET_COOKIE') {
			$k .= '_' . ++$this->cookieNum;
		}
		elseif (!$replace && isset($this->headers[$k])) {
			return false;
		}

		$this->headers[$k] = $s;

		if ($k === 'CONTENT_LENGTH') {
			$this->contentLength = (int)$e[1];
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
	 * Removes a header
	 * @param  string $s Header name. Example: 'Location'
	 * @return void
	 */
	public function removeHeader($s) {
		unset($this->headers[strtr(strtoupper($s), Generic::$htr)]);
	}

	/**
	 * Converts human-readable representation of size to number of bytes
	 * @param  string $value String of size
	 * @return integer
	 */
	public static function parseSize($value) {
		$l = substr($value, -1);

		if ($l === 'b' || $l === 'B') {
			return ((int)substr($value, 0, -1));
		}

		if ($l === 'k') {
			return ((int)substr($value, 0, -1) * 1000);
		}

		if ($l === 'K') {
			return ((int)substr($value, 0, -1) * 1024);
		}

		if ($l === 'm') {
			return ((int)substr($value, 0, -1) * 1000 * 1000);
		}

		if ($l === 'M') {
			return ((int)substr($value, 0, -1) * 1024 * 1024);
		}

		if ($l === 'g') {
			return ((int)substr($value, 0, -1) * 1000 * 1000 * 1000);
		}

		if ($l === 'G') {
			return ((int)substr($value, 0, -1) * 1024 * 1024 * 1024);
		}

		return (int)$value;
	}

	/**
	 * Called when file upload started
	 * @param  Input $in Input buffer
	 * @return void
	 */
	public function onUploadFileStart($in) {
		$this->freezeInput();
		FileSystem::tempnam(ini_get('upload_tmp_dir'), 'php', function ($fp) use ($in) {
			if (!$fp) {
				$in->curPart['fp']    = false;
				$in->curPart['error'] = UPLOAD_ERR_NO_TMP_DIR;
			}
			else {
				$in->curPart['fp']       = $fp;
				$in->curPart['tmp_name'] = $fp->path;
			}
			$this->unfreezeInput();
		});
	}

	/**
	 * Called when chunk of incoming file has arrived
	 * @param  Input   $in   Input buffer
	 * @param  boolean $last Last?
	 * @return void
	 */
	public function onUploadFileChunk($in, $last = false) {
		if ($in->curPart['error'] !== UPLOAD_ERR_OK) {
			// just drop the chunk
			return;
		}
		$cb = function ($fp, $result) use ($last, $in) {
			if ($last) {
				unset($in->curPart['fp']);
			}
			$this->unfreezeInput();
		};
		if ($in->writeChunkToFd($in->curPart['fp']->getFd())) {
			// We had written via internal method
			return;
		}
		// Internal method is not available, let's get chunk data into $chunk and then use File->write()
		$chunk = $in->getChunkString();
		if ($chunk === false) {
			return;
		}
		$this->freezeInput();
		$in->curPart['fp']->write($chunk, $cb);
	}

	/**
	 * Freeze input
	 * @return void
	 */
	protected function freezeInput() {
		$this->upstream->freezeInput();
		$this->attrs->input->freeze();
	}

	/**
	 * Unfreeze input
	 * @return void
	 */
	protected function unfreezeInput() {
		$this->upstream->unfreezeInput();
		if (isset($this->attrs->input)) {
			$this->attrs->input->unfreeze();
		}
	}

	/**
	 * Returns path to directory of temporary upload files
	 * @return string
	 */
	public function getUploadTempDir() {
		if ($r = ini_get('upload_tmp_dir')) {
			return $r;
		}
		return sys_get_temp_dir();
	}

	/**
	 * Tells whether the file was uploaded via HTTP POST
	 * @param  string  $path The filename being checked
	 * @return boolean       Whether if this is uploaded file
	 */
	public function isUploadedFile($path) {
		if (!$path) {
			return false;
		}
		if (strpos($path, $this->getUploadTempDir() . '/') !== 0) {
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
	 * Moves an uploaded file to a new location
	 * @param  string  $filename The filename of the uploaded file
	 * @param  string  $dest     The destination of the moved file
	 * @return boolean           Success
	 */
	public function moveUploadedFile($filename, $dest) {
		if (!$this->isUploadedFile($filename)) {
			return false;
		}
		return FileSystem::rename($filename, $dest);
	}

	/**
	 * Read request body from the file given in REQUEST_BODY_FILE parameter
	 * @return boolean Success
	 */
	public function readBodyFile() {
		if (!isset($this->attrs->server['REQUEST_BODY_FILE'])) {
			return false;
		}
		FileSystem::readfileChunked($this->attrs->server['REQUEST_BODY_FILE'],
			function ($file, $success) {
				$this->attrs->inputDone = true;
				if ($this->sleepTime === 0) {
					$this->wakeup();
				}
			},
			function ($file, $chunk) { // readed chunk
				$this->stdin($chunk);
			}
		);
		return true;
	}

	/**
	 * Replacement for default parse_str(), it supoorts UCS-2 like this: %uXXXX
	 * @param  string  $s      String to parse
	 * @param  array   &$var   Reference to the resulting array
	 * @param  boolean $header Header-style string
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
			$s = strtr($s, Generic::$hvaltr);
		}
		if (
				(stripos($s, '%u') !== false)
				&& preg_match('~(%u[a-f\d]{4}|%[c-f][a-f\d](?!%[89a-f][a-f\d]))~is', $s, $m)
		) {
			$s = preg_replace_callback('~%(u[a-f\d]{4}|[a-f\d]{2})~i', $cb, $s);
		}
		parse_str($s, $var);
	}

	/**
	 * Called after request finish
	 * @param  callable $cb Callback
	 * @return void
	 */
	protected function postFinishHandler($cb = null) {
		if (!$this->headers_sent) {
			$this->out('');
		}
		$this->sendfp       = null;
		if (isset($this->attrs->files)) {
			foreach ($this->attrs->files as $f) {
				if (isset($f['tmp_name'])) {
					FileSystem::unlink($f['tmp_name']);
				}
			}
		}
		if (isset($this->attrs->session)) {
			$this->sessionCommit($cb);
		}
		else {
			if ($cb) {
				call_user_func($cb);
			}
		}
	}
}
