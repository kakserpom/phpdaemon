<?php
namespace PHPDaemon\WebSocket;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/**
 * Web socket route
 *
 * @package Core
 *
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class Route implements RouteInterface {
	use \PHPDaemon\Traits\StaticObjectWatchdog;
	use \PHPDaemon\Traits\Sessions;
	use \PHPDaemon\Traits\DeferredEventHandlers;
	
	public $attrs;
	
	/**
	 * @var \PHPDaemon\Servers\WebSocket\Connection
	 */
	public $client; // Remote client
	/**
	 * @var \PHPDaemon\Core\AppInstance
	 */
	public $appInstance;

	protected $running = true;

	/**
	 * Get cookie by name
	 * @param  string $name Name of cookie
	 * @return string       Contents
	 */
	protected function getCookieStr($name) {
		return \PHPDaemon\HTTPRequest\Generic::getString($this->attrs->cookie[$name]);
	}


	/**
	 * Set session state
	 * @param  mixed $var
	 * @return void
	 */
	protected function setSessionState($var) {
		$this->attrs->session = $var;
	}

	/**
	 * Get session state
	 * @return mixed
	 */
	protected function getSessionState() {
		return $this->attrs->session;
	}

	
	/**
	 * Set the cookie
	 * @param  string  $name     Name of cookie
	 * @param  string  $value    Value
	 * @param  integer $maxage   Optional. Max-Age. Default is 0.
	 * @param  string  $path     Optional. Path. Default is empty string.
	 * @param  string  $domain   Optional. Domain. Default is empty string.
	 * @param  boolean $secure   Optional. Secure. Default is false.
	 * @param  boolean $HTTPOnly Optional. HTTPOnly. Default is false.
	 * @return void
	 */
	public function setcookie($name, $value = '', $maxage = 0, $path = '', $domain = '', $secure = false, $HTTPOnly = false) {
		$this->client->header(
			'Set-Cookie: ' . $name . '=' . rawurlencode($value)
			. (empty($domain) ? '' : '; Domain=' . $domain)
			. (empty($maxage) ? '' : '; Max-Age=' . $maxage)
			. (empty($path) ? '' : '; Path=' . $path)
			. (!$secure ? '' : '; Secure')
			. (!$HTTPOnly ? '' : '; HttpOnly'), false);
	}
	
	/**
	 * Called when client connected.
	 * @param \PHPDaemon\Servers\WebSocket\Connection $client Remote client
	 * @param \PHPDaemon\Core\AppInstance $appInstance
	 */
	public function __construct($client, $appInstance = null) {
		$this->client = $client;
		
		$this->attrs = new \stdClass;
		$this->attrs->get =& $client->get;
		$this->attrs->cookie =& $client->cookie;
		$this->attrs->server =& $client->server;
		$this->attrs->session = null;

		if ($appInstance) {
			$this->appInstance = $appInstance;
		}
	}
	
	/**
	 * Called when the request wakes up
	 * @return void
	 */
	public function onWakeup() {
		$this->running   = true;
		Daemon::$context = $this;
		$_SESSION = &$this->attrs->session;
		$_GET = &$this->attrs->get;
		$_POST = [];
		$_COOKIE = &$this->attrs->cookie;
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
	 * Called when the connection is handshaked.
	 * @return void
	 */
	public function onHandshake() {}
	
	
	/**
	 * Called when new frame is received
	 * @param string $data Frame's contents
	 * @param integer $type Frame's type
	 * @return void
	 */
	public function onFrame($data, $type) {}
	
	/**
	 * Uncaught exception handler
	 * @param $e
	 * @return boolean Handled?
	 */
	public function handleException($e) {
		return false;
	}

	/**
	 * Called when session finished.
	 * @return void
	 */
	public function onFinish() {
		$this->client = null;
	}

	/**
	 * Called when the worker is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function gracefulShutdown() {
		return true;
	}
}
