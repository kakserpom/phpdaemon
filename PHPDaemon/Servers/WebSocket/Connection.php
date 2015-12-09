<?php
namespace PHPDaemon\Servers\WebSocket;

use PHPDaemon\Core\Daemon;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\WebSocket\Route;
use PHPDaemon\Request\RequestHeadersAlreadySent;

class Connection extends \PHPDaemon\Network\Connection {
	use \PHPDaemon\Traits\DeferredEventHandlers;
	use \PHPDaemon\Traits\Sessions;

	/**
	 * @var integer Timeout
	 */
	protected $timeout = 120;

	protected $handshaked = false;
	protected $route;
	protected $writeReady = true;
	protected $extensions = [];
	protected $extensionsCleanRegex = '/(?:^|\W)x-webkit-/iS';
	
	protected $headers = [];
	protected $headers_sent = false;
	
	/**
	 * @var array _SERVER
	 */
	public $server = [];

	/**
	 * @var array _COOKIE
	 */
	public $cookie = [];

	/**
	 * @var array _GET
	 */
	public $get = [];
	

	protected $policyReqNotFound = false;
	protected $currentHeader;
	protected $EOL = "\r\n";

	/**
	 * @var boolean Is this connection running right now?
	 */
	protected $running = false;

	/**
	 * State: first line
	 */
	const STATE_FIRSTLINE  = 1;

	/**
	 * State: headers
	 */
	const STATE_HEADERS    = 2;

	/**
	 * State: content
	 */
	const STATE_CONTENT    = 3;

	/**
	 * State: prehandshake
	 */
	const STATE_PREHANDSHAKE = 5;

	/**
	 * State: handshaked
	 */
	const STATE_HANDSHAKED = 6;

	const STRING = NULL;

	const BINARY = NULL;

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
	 * Called when the stream is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		$this->setWatermark(null, $this->pool->maxAllowedPacket + 100);
	}

	/**
	 * Get real frame type identificator
	 * @param $type
	 * @return integer
	 */
	public function getFrameType($type) {
		if (is_int($type)) {
			return $type;
		}
		if ($type === null) {
			$type = 'STRING';
		}
		$frametype = @constant(get_class($this) . '::' . $type);
		if ($frametype === null) {
			Daemon::log(__METHOD__ . ' : Undefined frametype "' . $type . '"');
		}
		return $frametype;
	}


	/**
	 * Called when connection is inherited from HTTP request
	 * @param  object $req
	 * @return void
	 */
	public function onInheritanceFromRequest($req) {
		$this->state  = self::STATE_HEADERS;
		$this->addr   = $req->attrs->server['REMOTE_ADDR'];
		$this->server = $req->attrs->server;
		$this->get = $req->attrs->get;
		$this->prependInput("\r\n");
		$this->onRead();
	}

	/**
	 * Sends a frame.
	 * @param  string   $data  Frame's data.
	 * @param  string   $type  Frame's type. ("STRING" OR "BINARY")
	 * @param  callable $cb    Optional. Callback called when the frame is received by client.
	 * @callback $cb ( )
	 * @return boolean         Success.
	 */
	public function sendFrame($data, $type = null, $cb = null) {
		return false;
	}

	/**
	 * Event of Connection.
	 * @return void
	 */
	public function onFinish() {

		$this->sendFrame('', 'CONNCLOSE');
		
		if ($this->route) {
			$this->route->onFinish();
		}
		$this->route = null;
	}

	/**
	 * Uncaught exception handler
	 * @param  Exception $e
	 * @return boolean      Handled?
	 */
	public function handleException($e) {
		if (!isset($this->route)) {
			return false;
		}
		return $this->route->handleException($e);
	}

	/**
	 * Called when new frame received.
	 * @param  string $data Frame's data.
	 * @param  string $type Frame's type ("STRING" OR "BINARY").
	 * @return boolean      Success.
	 */
	public function onFrame($data, $type) {
		if (!isset($this->route)) {
			return false;
		}
		try {
			$this->route->onWakeup();
			$this->route->onFrame($data, $type);
		} catch (\Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
		$this->route->onSleep();
		return true;
	}

	/**
	 * Called when the worker is going to shutdown.
	 * @return boolean Ready to shutdown ?
	 */
	public function gracefulShutdown() {
		if ((!$this->route) || $this->route->gracefulShutdown()) {
			$this->finish();
			return true;
		}
		return FALSE;
	}


	/**
	 * Called when we're going to handshake.
	 * @return boolean               Handshake status
	 */
	public function handshake() {
		$this->route = $this->pool->getRoute($this->server['DOCUMENT_URI'], $this);
		if (!$this->route) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot handshake session for client "' . $this->addr . '"');
			$this->finish();
			return false;
		}

		if (method_exists($this->route, 'onBeforeHandshake')) {
			$this->route->onWakeup();
			$ret = $this->route->onBeforeHandshake(function() {
				$this->handshakeAfter();
			});
			$this->route->onSleep();
			if ($ret !== false) {
				return;
			}
		}

		$this->handshakeAfter();
	}

	protected function handshakeAfter() {
		$extraHeaders = '';
		foreach ($this->headers as $k => $line) {
			if ($k !== 'STATUS') {
				$extraHeaders .= $line . "\r\n";
			}
		}

		if (!$this->sendHandshakeReply($extraHeaders)) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Handshake protocol failure for client "' . $this->addr . '"');
			$this->finish();
			return false;
		}

		$this->handshaked = true;
		$this->headers_sent = true;
		$this->state = static::STATE_HANDSHAKED;
		if (is_callable([$this->route, 'onHandshake'])) {
			$this->route->onWakeup();
			$this->route->onHandshake();
			$this->route->onSleep();
		}
		return true;
	}
	
	/**
	 * Send Bad request
	 * @return void
	 */
	public function badRequest() {
		$this->state = self::STATE_STANDBY;
		$this->write("400 Bad Request\r\n\r\n<html><head><title>400 Bad Request</title></head><body bgcolor=\"white\"><center><h1>400 Bad Request</h1></center></body></html>");
		$this->finish();
	}

	/**
	 * Read first line of HTTP request
	 * @return boolean|null Success
	 */
	protected function httpReadFirstline() {
		if (($l = $this->readline()) === null) {
			return null;
		}
		$e = explode(' ', $l);
		$u = isset($e[1]) ? parse_url($e[1]) : false;
		if ($u === false) {
			$this->badRequest();
			return false;
		}
		if (!isset($u['path'])) {
			$u['path'] = null;
		}
		if (isset($u['host'])) {
			$this->server['HTTP_HOST'] = $u['host'];
		}
		$srv                       = & $this->server;
		$srv['REQUEST_METHOD']     = $e[0];
		$srv['REQUEST_TIME']       = time();
		$srv['REQUEST_TIME_FLOAT'] = microtime(true);
		$srv['REQUEST_URI']        = $u['path'] . (isset($u['query']) ? '?' . $u['query'] : '');
		$srv['DOCUMENT_URI']       = $u['path'];
		$srv['PHP_SELF']           = $u['path'];
		$srv['QUERY_STRING']       = isset($u['query']) ? $u['query'] : null;
		$srv['SCRIPT_NAME']        = $srv['DOCUMENT_URI'] = isset($u['path']) ? $u['path'] : '/';
		$srv['SERVER_PROTOCOL']    = isset($e[2]) ? $e[2] : 'HTTP/1.1';
		$srv['REMOTE_ADDR']        = $this->addr;
		$srv['REMOTE_PORT']        = $this->port;
		return true;
	}

	/**
	 * Read headers line-by-line
	 * @return boolean|null Success
	 */
	protected function httpReadHeaders() {
		while (($l = $this->readLine()) !== null) {
			if ($l === '') {
				return true;
			}
			$e = explode(': ', $l);
			if (isset($e[1])) {
				$this->currentHeader                = 'HTTP_' . strtoupper(strtr($e[0], Generic::$htr));
				$this->server[$this->currentHeader] = $e[1];
			}
			elseif (($e[0][0] === "\t" || $e[0][0] === "\x20") && $this->currentHeader) {
				// multiline header continued
				$this->server[$this->currentHeader] .= $e[0];
			}
			else {
				// whatever client speaks is not HTTP anymore
				$this->badRequest();
				return false;
			}
		}
		return null;
	}

	/**
	 * Called when new data received.
	 * @return void
	 */
	protected function onRead() {
		if (!$this->policyReqNotFound) {
			$d = $this->drainIfMatch("<policy-file-request/>\x00");
			if ($d === null) { // partially match
				return;
			}
			if ($d) {
				if (($FP = \PHPDaemon\Servers\FlashPolicy\Pool::getInstance($this->pool->config->fpsname->value, false)) && $FP->policyData) {
					$this->write($FP->policyData . "\x00");
				}
				$this->finish();
				return;
			}
			else {
				$this->policyReqNotFound = true;
			}
		}
		start:
		if ($this->finished) {
			return;
		}
		if ($this->state === self::STATE_STANDBY) {
			$this->state = self::STATE_FIRSTLINE;
		}
		if ($this->state === self::STATE_FIRSTLINE) {
			if (!$this->httpReadFirstline()) {
				return;
			}
			$this->state = self::STATE_HEADERS;
		}

		if ($this->state === self::STATE_HEADERS) {
			if (!$this->httpReadHeaders()) {
				return;
			}
			if (!$this->httpProcessHeaders()) {
				$this->finish();
				return;
			}
			$this->state = self::STATE_CONTENT;
		}
		if ($this->state === self::STATE_CONTENT) {
			$this->state = self::STATE_PREHANDSHAKE;
		}
	}

	/**
	 * Process headers
	 * @return bool
	 */
	protected function httpProcessHeaders() {
		$this->state = self::STATE_PREHANDSHAKE;
		if (isset($this->server['HTTP_SEC_WEBSOCKET_EXTENSIONS'])) {
			$str              = strtolower($this->server['HTTP_SEC_WEBSOCKET_EXTENSIONS']);
			$str              = preg_replace($this->extensionsCleanRegex, '', $str);
			$this->extensions = explode(', ', $str);
		}
		if (!isset($this->server['HTTP_CONNECTION'])
				|| (!preg_match('~(?:^|\W)Upgrade(?:\W|$)~i', $this->server['HTTP_CONNECTION'])) // "Upgrade" is not always alone (ie. "Connection: Keep-alive, Upgrade")
				|| !isset($this->server['HTTP_UPGRADE'])
				|| (strtolower($this->server['HTTP_UPGRADE']) !== 'websocket') // Lowercase compare important
		) {
			$this->finish();
			return false;
		}
		if (isset($this->server['HTTP_COOKIE'])) {
			Generic::parse_str(strtr($this->server['HTTP_COOKIE'], Generic::$hvaltr), $this->cookie);
		}
		if (isset($this->server['QUERY_STRING'])) {
			Generic::parse_str($this->server['QUERY_STRING'], $this->get);
		}
		// ----------------------------------------------------------
		// Protocol discovery, based on HTTP headers...
		// ----------------------------------------------------------
		if (isset($this->server['HTTP_SEC_WEBSOCKET_VERSION'])) { // HYBI
			if ($this->server['HTTP_SEC_WEBSOCKET_VERSION'] === '8') { // Version 8 (FF7, Chrome14)
				$this->switchToProtocol('V13');
			}
			elseif ($this->server['HTTP_SEC_WEBSOCKET_VERSION'] === '13') { // newest protocol
				$this->switchToProtocol('V13');
			}
			else {
				Daemon::$process->log(get_class($this) . '::' . __METHOD__ . " : Websocket protocol version " . $this->server['HTTP_SEC_WEBSOCKET_VERSION'] . ' is not yet supported for client "' . $this->addr . '"');
				$this->finish();
				return false;
			}
		}
		elseif (!isset($this->server['HTTP_SEC_WEBSOCKET_KEY1']) || !isset($this->server['HTTP_SEC_WEBSOCKET_KEY2'])) {
			$this->switchToProtocol('VE');
		}
		else { // Defaulting to HIXIE (Safari5 and many non-browser clients...)
			$this->switchToProtocol('V0');
		}
		// ----------------------------------------------------------
		// End of protocol discovery
		// ----------------------------------------------------------
		return true;
	}

	protected function switchToProtocol($proto) {
		$class = '\\PHPDaemon\\Servers\\WebSocket\\Protocols\\' . $proto;
		$conn  = new $class(null, $this->pool);
		$this->pool->attach($conn);
		$conn->setFd($this->getFd(), $this->getBev());
		$this->unsetFd();
		$this->pool->detach($this);
		$conn->onInheritance($this);
	}

	public function onInheritance($conn) {
		$this->server = $conn->server;
		$this->cookie = $conn->cookie;
		$this->get = $conn->get;
		$this->state = self::STATE_PREHANDSHAKE;
		$this->addr = $conn->addr;
		$this->onRead();
	}


	/**
	 * Send HTTP-status
	 * @throws RequestHeadersAlreadySent
	 * @param  integer $code Code
	 * @return boolean       Success
	 */
	public function status($code = 200) {
		return false;
	}

	/**
	 * Send the header
	 * @param  string  $s       Header. Example: 'Location: http://php.net/'
	 * @param  boolean $replace Optional. Replace?
	 * @param  boolean $code    Optional. HTTP response code
	 * @throws \PHPDaemon\Request\RequestHeadersAlreadySent
	 * @return boolean          Success
	 */
	public function header($s, $replace = true, $code = false) {
		if ($code) {
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
}
