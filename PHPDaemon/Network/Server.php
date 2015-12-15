<?php
namespace PHPDaemon\Network;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Network\Pool;

/**
 * Network server pattern
 * @package PHPDaemon\Network
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
abstract class Server extends Pool {

	/**
	 * @var \PHPDaemon\Structures\ObjectStorage Bound sockets
	 */
	protected $bound;

	/**
	 * @var array|null Allowed clients
	 */
	public $allowedClients = null;
	
	/**
	 * Constructor
	 * @param array   $config Config variables
	 * @param boolean $init
	 */
	public function __construct($config = [], $init = true) {
		parent::__construct($config, false);
		$this->bound = new \PHPDaemon\Structures\ObjectStorage;
		if (isset($this->config->listen)) {
			$this->bindSockets($this->config->listen->value);
		}
		if ($init) {
			$this->init();
		}
	}

	/**
	 * Called when ConnectionPool is finished
	 * @return void
	 */
	protected function onFinish() {
		$this->closeBound();
		parent::onFinish();
	}
	
	/**
	 * Bind given sockets
	 * @param  mixed   $addrs Addresses to bind
	 * @param  integer $max   Maximum
	 * @return integer        Number of bound
	 */
	public function bindSockets($addrs = [], $max = 0) {
		if (is_string($addrs)) {
			$addrs = array_map('trim', explode(',', $addrs));
		}
		$n = 0;
		foreach ($addrs as $addr) {
			if ($this->bindSocket($addr)) {
				++$n;
			}
			if ($max > 0 && ($n >= $max)) {
				return $n;
			}
		}
		return $n;
	}

	/**
	 * Bind given socket
	 * @param  string  $uri Address to bind
	 * @return boolean      Success
	 */
	public function bindSocket($uri) {
		$u      = \PHPDaemon\Config\Object::parseCfgUri($uri);
		$scheme = $u['scheme'];
		if ($scheme === 'unix') {
			$socket = new \PHPDaemon\BoundSocket\UNIX($u);

		}
		elseif ($scheme === 'udp') {
			$socket = new \PHPDaemon\BoundSocket\UDP($u);
			if (isset($this->config->port->value)) {
				$socket->setDefaultPort($this->config->port->value);
			}
		}
		elseif ($scheme === 'tcp') {
			$socket = new \PHPDaemon\BoundSocket\TCP($u);
			if (isset($this->config->port->value)) {
				$socket->setDefaultPort($this->config->port->value);
			}
		}
		else {
			Daemon::log(get_class($this) . ': enable to bind \'' . $uri . '\': scheme \'' . $scheme . '\' is not supported');
			return false;
		}
		$socket->attachTo($this);
		if ($socket->bindSocket()) {
			if ($this->enabled) {
				$socket->enable();
			}
			return true;
		}
		return false;
	}

	/**
	 * Applies config
	 * @return void
	 */
	protected function applyConfig() {
		parent::applyConfig();
		foreach ($this->config as $k => $v) {
			if (is_object($v) && $v instanceof \PHPDaemon\Config\Entry\Generic) {
				$v = $v->getValue();
			}
			$k = strtolower($k);
			if ($k === 'allowedclients') {
				$this->allowedClients = $v;
			}
		}
	}

	/**
	 * Called when ConnectionPool is now enabled
	 * @return void
	 */
	protected function onEnable() {
		if ($this->bound) {
			$this->bound->each('enable');
		}
	}

	/**
	 * Called when ConnectionPool is now disabled
	 * @return void
	 */
	protected function onDisable() {
		if ($this->bound) {
			$this->bound->each('disable');
		}
	}

	/**
	 * Attach Generic
	 * @param  \PHPDaemon\BoundSocket\Generic $bound Generic
	 * @param  mixed $inf Info
	 * @return void
	 */
	public function attachBound(\PHPDaemon\BoundSocket\Generic $bound, $inf = null) {
		$this->bound->attach($bound, $inf);
	}

	/**
	 * Detach Generic
	 * @param  \PHPDaemon\BoundSocket\Generic $bound Generic
	 * @return void
	 */
	public function detachBound(\PHPDaemon\BoundSocket\Generic $bound) {
		$this->bound->detach($bound);
	}

	/**
	 * Close each of binded sockets
	 * @return void
	 */
	public function closeBound() {
		$this->bound->each('close');
	}

	/**
	 * Called when a request to HTTP-server looks like another connection
	 * @param  object  $req     Request
	 * @param  object  $oldConn Connection
	 * @return boolean Success
	 */
	public function inheritFromRequest($req, $oldConn) {
		if (!$oldConn || !$req) {
			return false;
		}
		$class = $this->connectionClass;
		$conn  = new $class(null, $this);
		$this->attach($conn);
		$conn->setFd($oldConn->getFd(), $oldConn->getBev());
		$oldConn->unsetFd();
		$oldConn->pool->detach($oldConn);
		$conn->onInheritanceFromRequest($req);
		if ($req instanceof \PHPDaemon\Request\Generic) {
			$req->free();
		}
		return true;
	}
}
