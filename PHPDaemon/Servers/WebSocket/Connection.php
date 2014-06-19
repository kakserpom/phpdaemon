<?php
namespace PHPDaemon\Servers\WebSocket;

use PHPDaemon\Core\Daemon;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\WebSocket\ProtocolV0;
use PHPDaemon\WebSocket\ProtocolV13;
use PHPDaemon\WebSocket\ProtocolVE;
use PHPDaemon\WebSocket\Route;

class Connection extends \PHPDaemon\Network\Connection {
	use \PHPDaemon\Traits\DeferredEventHandlers;
	use \PHPDaemon\Traits\Sessions;

	/**
	 * Timeout
	 * @var integer
	 */
	protected $timeout = 120;

	protected $handshaked = false;
	protected $route;
	protected $writeReady = true;
	protected $extensions = [];
	protected $extensionsCleanRegex = '/(?:^|\W)x-webkit-/iS';
	protected $buf = '';
	/**
	 * @var \PHPDaemon\WebSocket\Protocol
	 */
	protected $protocol;
	protected $policyReqNotFound = false;
	protected $currentHeader;
	protected $EOL = "\r\n";
	public $session;

	protected $headers = [];

	/**
	 * Is this connection running right now?
	 * @var boolean
	 */
	protected $running = false;

	/**
	 * State: first line
	 * @var integer
	 */
	const STATE_FIRSTLINE  = 1;

	/**
	 * State: headers
	 * @var integer
	 */
	const STATE_HEADERS    = 2;

	/**
	 * State: content
	 * @var integer
	 */
	const STATE_CONTENT    = 3;

	/**
	 * State: prehandshake
	 * @var integer
	 */
	const STATE_PREHANDSHAKE = 5;

	/**
	 * State: handshaked
	 * @var integer
	 */
	const STATE_HANDSHAKED = 6;

	/**
	 * Frame buffer
	 * @var string
	 */
	public $framebuf = '';

	/**
	 * _SERVER
	 * @var array
	 */
	public $server = [];

	/**
	 * _COOKIE
	 * @var array
	 */
	public $cookie = [];

	protected $headers_sent = false;


	/**
	 * _GET
	 * @var array
	 */
	public $get = [];

	/**
	 * _POST
	 * @var null
	 */
	public $post = null;

	/**
	 * Content length from header() method
	 * @var integer
	 */
	protected $contentLength;

	/**
	 * Number of outgoing cookie-headers
	 * @var integer
	 */
	protected $cookieNum = 0;

	/**
	 * Replacement pairs for processing some header values in parse_str()
	 * @var array hash
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
	 * Get cookie by name
	 * @param string $name Name of cookie
	 * @return string Contents
	 */
	protected function getCookieStr($name) {
		return \PHPDaemon\HTTPRequest\Generic::getString($this->cookie[$name]);
	}


	/**
	 * Set session state
	 * @param mixed
	 * @return void
	 */
	protected function setSessionState($var) {
		$this->session = $var;
	}

	/**
	 * Get session state
	 * @return mixed
	 */
	protected function getSessionState() {
		return $this->session;
	}

	/**
	 * Called when the request wakes up
	 * @return void
	 */
	public function onWakeup() {
		$this->running   = true;
		Daemon::$context = $this;
		$_SESSION = &$this->session;
		$_GET = &$this->get;
		$_POST = &$this->post; // supposed to be null
		$_COOKIE = &$this->cookie;
		Daemon::$process->setState(Daemon::WSTATE_BUSY);
	}

	/**
	 * Called when the request starts sleep
	 * @return void
	 */
	public function onSleep() {
		Daemon::$context = null;
		$this->running   = false;
		unset($_SESSION, $_GET, $_POST, $_COOKIE);
		Daemon::$process->setState(Daemon::WSTATE_IDLE);
	}

	/**
	 * Called when connection is inherited from HTTP request
	 * @param $req
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
	 * @param string $data  Frame's data.
	 * @param string $type  Frame's type. ("STRING" OR "BINARY")
	 * @param callback $cb Optional. Callback called when the frame is received by client.
	 * @return boolean Success.
	 */
	public function sendFrame($data, $type = null, $cb = null) {
		if (!$this->handshaked) {
			return false;
		}

		if ($this->finished) {
			return false;
		}

		if (!isset($this->protocol)) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot find session-related websocket protocol for client ' . $this->addr);
			return false;
		}

		$this->protocol->sendFrame($data, $type);
		if ($cb) {
			$this->onWriteOnce($cb);
		}
		return true;
	}

	/**
	 * Event of Connection.
	 * @return void
	 */
	public function onFinish() {
		if (isset($this->route)) {
			$this->route->onFinish();
		}
		$this->route = null;
		if ($this->protocol) {
			$this->protocol->conn = null;
			$this->protocol       = null;
		}
	}

	/**
	 * Uncaught exception handler
	 * @param $e
	 * @return boolean Handled?
	 */
	public function handleException($e) {
		if (!isset($this->route)) {
			return false;
		}
		return $this->route->handleException($e);
	}

	/**
	 * Called when new frame received.
	 * @param string Frame's data.
	 * @param string Frame's type ("STRING" OR "BINARY").
	 * @return boolean Success.
	 */
	public function onFrame($data, $type) {
		if (!isset($this->route)) {
			return false;
		}
		$this->onWakeup();
		$this->route->onFrame($data, $type);
		$this->onSleep();
		return true;
	}

	/**
	 * Called when the connection is handshaked.
	 * @return boolean Ready to handshake ?
	 */
	public function onHandshake() {

		$e         = explode('/', $this->server['DOCUMENT_URI']);
		$routeName = isset($e[1]) ? $e[1] : '';

		if (!isset($this->pool->routes[$routeName])) {
			if (Daemon::$config->logerrors->value) {
				Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : undefined route "' . $routeName . '" for client "' . $this->addr . '"');
			}

			return false;
		}
		$route = $this->pool->routes[$routeName];
		if (is_string($route)) { // if we have a class name
			if (class_exists($route)) {
				$this->onWakeup();
				new $route($this);
				$this->onSleep();
			}
			else {
				return false;
			}
		}
		elseif (is_array($route) || is_object($route)) { // if we have a lambda object or callback reference
			if (is_callable($route)) {
				$ret = call_user_func($route, $this); // calling the route callback
				if ($ret instanceof Route) {
					$this->route = $ret;
				}
				else {
					return false;
				}
			}
			else {
				return false;
			}
		}
		else {
			return false;
		}

		if (!isset($this->protocol)) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot find session-related websocket protocol for client "' . $this->addr . '"');
			return false;
		}

		if ($this->protocol->onHandshake() === false) {
			return false;
		}

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
	 * @return boolean Handshake status
	 */
	public function handshake($extraHeaders = null) {

		if (!$this->onHandshake()) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot handshake session for client "' . $this->addr . '"');
			$this->finish();
			return false;
		}

		// Handshaking...
		$handshake = $this->protocol->getHandshakeReply($this->buf, $extraHeaders);
		if ($handshake === 0) { // not enough data yet
			return 0;
		}
		if (!$handshake) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Handshake protocol failure for client "' . $this->addr . '"');
			$this->finish();
			return false;
		}
		if ($extraHeaders === null && method_exists($this->route, 'onBeforeHandshake')) {
			$this->onWakeup();
			$this->route->onBeforeHandshake(function($cb) {
				$h = '';
				foreach ($this->headers as $k => $line) {
					if ($k !== 'STATUS') {
						$h .= $line . "\r\n";
					}
				}
				if ($this->handshake($h)) {
					if ($cb !== null) {
						call_user_func($cb);
					}
				}
			});
			$this->onSleep();
			return;
		}

		if (!isset($this->protocol)) {
			Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot find session-related websocket protocol for client "' . $this->addr . '"');
			$this->finish();
			return false;
		}
		$this->write($handshake);
		$this->buf   = '';
		$this->handshaked = true;
		$this->headers_sent = true;
		$this->state = static::STATE_HANDSHAKED;
		if (is_callable([$this->route, 'onHandshake'])) {
			$this->onWakeup();
			$this->route->onHandshake();
			$this->onSleep();
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
	 * @return boolean Success
	 * @return void
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
	 * @return boolean Success
	 * @return void
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

		if ($this->state === self::STATE_PREHANDSHAKE) {
			$this->buf .= $this->read(1024);
			if (!$this->handshake()) {
				return;
			}
		}
		if ($this->state === self::STATE_HANDSHAKED) {
			if (!isset($this->protocol)) {
				Daemon::$process->log(get_class($this) . '::' . __METHOD__ . ' : Cannot find session-related websocket protocol for client "' . $this->addr . '"');
				$this->finish();
				return;
			}
			$this->protocol->onRead();
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
			if ($this->server['HTTP_SEC_WEBSOCKET_VERSION'] == '8') { // Version 8 (FF7, Chrome14)
				$this->protocol = new ProtocolV13($this);
			}
			elseif ($this->server['HTTP_SEC_WEBSOCKET_VERSION'] == '13') { // newest protocol
				$this->protocol = new ProtocolV13($this);
			}
			else {
				Daemon::$process->log(get_class($this) . '::' . __METHOD__ . " : Websocket protocol version " . $this->server['HTTP_SEC_WEBSOCKET_VERSION'] . ' is not yet supported for client "' . $this->addr . '"');
				$this->finish();
				return false;
			}
		}
		elseif (!isset($this->server['HTTP_SEC_WEBSOCKET_KEY1']) || !isset($this->server['HTTP_SEC_WEBSOCKET_KEY2'])) {
			$this->protocol = new ProtocolVE($this);
		}
		else { // Defaulting to HIXIE (Safari5 and many non-browser clients...)
			$this->protocol = new ProtocolV0($this);
		}
		// ----------------------------------------------------------
		// End of protocol discovery
		// ----------------------------------------------------------
		return true;
	}

	/**
	 * Set the cookie
	 * @param string $name         Name of cookie
	 * @param string $value        Value
	 * @param integer $maxage      . Optional. Max-Age. Default is 0.
	 * @param string $path         . Optional. Path. Default is empty string.
	 * @param bool|string $domain  . Optional. Secure. Default is false.
	 * @param boolean $secure      . Optional. HTTPOnly. Default is false.
	 * @param bool $HTTPOnly
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
	 * Send HTTP-status
	 * @throws RequestHeadersAlreadySent
	 * @param int $code Code
	 * @return boolean Success
	 */
	public function status($code = 200) {
		return false;
	}

	/**
	 * Send the header
	 * @param string $s        Header. Example: 'Location: http://php.net/'
	 * @param boolean $replace Optional. Replace?
	 * @param bool|int $code   Optional. HTTP response code.
	 * @throws \PHPDaemon\Request\RequestHeadersAlreadySent
	 * @return boolean Success
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
