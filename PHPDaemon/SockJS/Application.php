<?php
namespace PHPDaemon\SockJS;

use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Structures\ObjectStorage;
use PHPDaemon\Core\Debug;
use PHPDaemon\Servers\WebSocket\Pool as WebSocketPool;

/**
 * @package    Libraries
 * @subpackage SockJS
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Application extends \PHPDaemon\Core\AppInstance {
	protected $redis;
	protected $sessions;

	public $wss;

	/**
	 * Setting default config options
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			/* [string] @todo redis-name */
			'redis-name' => '',

			/* [string] @todo redis-prefix */
			'redis-prefix' => 'sockjs:',

			/* [string] @todo wss-name */
			'wss-name' => '',
			
			/* [Double] @todo batch-delay */
			'batch-delay' => new \PHPDaemon\Config\Entry\Double('0.05'),
			
			/* [Double] @todo heartbeat-interval */
			'heartbeat-interval' => new \PHPDaemon\Config\Entry\Double('25'),
			
			/* [Time] @todo dead-session-timeout */
			'dead-session-timeout' => new \PHPDaemon\Config\Entry\Time('1h'),
			
			/* [Size] @todo gc-max-response-size */
			'gc-max-response-size' => new \PHPDaemon\Config\Entry\Size('128k'),
			
			/* [Time] @todo network-timeout-read */
			'network-timeout-read' => new \PHPDaemon\Config\Entry\Time('2h'),
			
			/* [Time] @todo network-timeout-write */
			'network-timeout-write' => new \PHPDaemon\Config\Entry\Time('120s'),
		];
	}

	/**
	 * getLocalSubscribersCount
	 * @param  string $chan
	 * @return integer
	 */
	public function getLocalSubscribersCount($chan) {
		return $this->redis->getLocalSubscribersCount($this->config->redisprefix->value . $chan);
	}

	/**
	 * subscribe
	 * @param  string   $chan [@todo description]
	 * @param  callable $cb   [@todo description]
	 * @param  callable $opcb [@todo description]
	 * @callback $cb ( )
	 * @callback $opcb ( )
	 * @return void
	 */
	public function subscribe($chan, $cb, $opcb = null) {
		$this->redis->subscribe($this->config->redisprefix->value . $chan, $cb, $opcb);
	}

	/**
	 * setnx
	 * @param  string   $key   [@todo description]
	 * @param  mixed    $value [@todo description]
	 * @param  callable $cb    [@todo description]
	 * @callback $cb ( )
	 * @return void
	 */
	public function setnx($key, $value, $cb = null) {
		$this->redis->setnx($this->config->redisprefix->value . $key, $value, $cb);
	}

	/**
	 * setkey
	 * @param  string   $key   [@todo description]
	 * @param  mixed    $value [@todo description]
	 * @param  callable $cb    [@todo description]
	 * @callback $cb ( )
	 * @return void
	 */
	public function setkey($key, $value, $cb = null) {
		$this->redis->set($this->config->redisprefix->value . $key, $value, $cb);
	}

	/**
	 * getkey
	 * @param  string   $key   [@todo description]
	 * @param  callable $cb    [@todo description]
	 * @callback $cb ( )
	 * @return void
	 */
	public function getkey($key, $cb = null) {
		$this->redis->get($this->config->redisprefix->value . $key, $cb);
	}

	/**
	 * expire
	 * @param  string   $key     [@todo description]
	 * @param  integer  $seconds [@todo description]
	 * @param  callable $cb      [@todo description]
	 * @callback $cb ( )
	 * @return void
	 */
	public function expire($key, $seconds, $cb = null) {
		$this->redis->expire($this->config->redisprefix->value . $key, $seconds, $cb);
	}

	/**
	 * unsubscribe
	 * @param  string   $chan [@todo description]
	 * @param  callable $cb   [@todo description]
	 * @param  callable $opcb [@todo description]
	 * @callback $cb ( )
	 * @callback $opcb ( )
	 * @return void
	 */
	public function unsubscribe($chan, $cb, $opcb = null) {
		$this->redis->unsubscribe($this->config->redisprefix->value . $chan, $cb, $opcb);
	}

	/**
	 * unsubscribeReal
	 * @param  string   $chan [@todo description]
	 * @param  callable $opcb [@todo description]
	 * @callback $opcb ( )
	 * @return void
	 */
	public function unsubscribeReal($chan, $opcb = null) {
		$this->redis->unsubscribeReal($this->config->redisprefix->value . $chan, $opcb);
	}

	/**
	 * publish
	 * @param  string   $chan [@todo description]
	 * @param  callable $cb   [@todo description]
	 * @param  callable $opcb [@todo description]
	 * @callback $cb ( )
	 * @callback $opcb ( )
	 * @return void
	 */
	public function publish($chan, $cb, $opcb = null) {
		$this->redis->publish($this->config->redisprefix->value . $chan, $cb, $opcb);
	}

	/**
	 * Called when the worker is ready to go
	 * @return void
	 */
	public function onReady() {
		$this->redis = \PHPDaemon\Clients\Redis\Pool::getInstance($this->config->redisname->value);
		$this->sessions = new ObjectStorage;
		$this->wss = new ObjectStorage;
		foreach (preg_split('~\s*;\s*~', $this->config->wssname->value) as $wssname) {
			$this->attachWss(WebSocketPool::getInstance(trim($wssname)));
		}
	}

	/**
	 * onFinish
	 * @return void
	 */
	public function onFinish() {
		foreach ($this->attachedTo as $wss) {
			$this->detachWss($wss);
		}
		parent::onFinish();
	}

	/**
	 * attachWss
	 * @param \PHPDaemon\Network\Pool $wss
	 * @return boolean
	 */
	public function attachWss($wss) {
		if ($this->wss->contains($wss)) {
			return false;
		}
		$this->wss->attach($wss);
		$wss->bind('customTransport', [$this, 'wsHandler']);
		return true;
	}

	/**
	 * wsHandler
	 * @param  object   $ws     [@todo description]
	 * @param  string   $path   [@todo description]
	 * @param  object   $client [@todo description]
	 * @param  callable $state  [@todo description]
	 * @callback $state ( object $route )
	 * @return boolean
	 */
	public function wsHandler($ws, $path, $client, $state) {
		$e = explode('/', $path);
		$method = array_pop($e);
		$serverId = null;
		$sessId = null;
		if ($method !== 'websocket') {
			return false;
		}
		if (sizeof($e) < 3 || !isset($e[sizeof($e) - 2]) || !ctype_digit($e[sizeof($e) - 2])) {
			return false;
		}
		$sessId = array_pop($e);
		$serverId = array_pop($e);
		$path = implode('/', $e);
		$client = new WebSocketConnectionProxy($this, $client);
		$route = $ws->getRoute($path, $client, true);
		if (!$route) {
			$state($route);
			return false;
		}
		$route = new WebSocketRouteProxy($this, $route);
		$state($route);
		return true;
	}

	/**
	 * detachWss
	 * @param  object $wss [@todo description]
	 * @return boolean
	 */
	public function detachWss($wss) {
		if (!$this->wss->contains($wss)) {
			return false;
		}
		$this->wss->detach($wss);
		$wss->unbind('transport', [$this, 'wsHandler']);
		return true;
	}

	/**
	 * beginSession
	 * @param  string $path   [@todo description]
	 * @param  string $sessId [@todo description]
	 * @param  string $server [@todo description]
	 * @return object
	 */
	public function beginSession($path, $sessId, $server) {
		$session = new Session($this, $sessId, $server);
		foreach ($this->wss as $wss) {
			if ($session->route = $wss->getRoute($path, $session)) {
				break;
			}
		}
		if (!$session->route) {
			return false;
		}
		$this->sessions->attach($session);
		$session->onHandshake();
		return $session;
	}

	/**
	 * getRouteOptions
	 * @param  string $path [@todo description]
	 * @return array
	 */
	public function getRouteOptions($path) {
		$opts = [
			'websocket' => true,
			'origins' => ['*:*'],
			'cookie_needed' => false,
		];
		foreach ($this->wss as $wss) {
			if ($wss->routeExists($path)) {
				foreach ($wss->getRouteOptions($path) as $k => $v) {
					$opts[$k] = $v;
				}
				break;
			}
		}
		return $opts;
	}

	/**
	 * endSession
	 * @param Session $session
	 * @return void
	 */
	public function endSession($session) {
		$this->sessions->detach($session);
	}

	/**
	 * Creates Request.
	 * @param  object $req      Request.
	 * @param  object $upstream Upstream application instance.
	 * @return object           Request.
	 */
	public function beginRequest($req, $upstream) {
		$e = array_map('rawurldecode', explode('/', $req->attrs->server['DOCUMENT_URI']));
		
		$serverId = null;
		$sessId = null;

		/* Route discovery */
		$path = null;
		$extra = [];
		do {
			foreach ($this->wss as $wss) {
				$try = implode('/', $e);
				if ($try === '') {
					$try = '/';
				}
				if ($wss->routeExists($try)) {
					$path = $try;
					break 2;
				}
			}
		 	array_unshift($extra, array_pop($e));
		} while (sizeof($e) > 0);

		if ($path === null) {
			return $this->callMethod('NotFound', $req, $upstream);
		}

		if (sizeof($extra) > 0 && end($extra) === '') {
			array_pop($extra);
		}

		$method = sizeof($extra) ? array_pop($extra) : null;
		
		if ($method === null) {
			$method = 'Welcome';
		}
		elseif ($method === 'info') {

		}
		elseif (preg_match('~^iframe(?:-([^/]*))?\.html$~', $method, $m)) {
			$method = 'Iframe';
			$version = isset($m[1]) ? $m[1] : null;
		} else {
			if (sizeof($extra) < 2) {
				return $this->callMethod('NotFound', $req, $upstream);	
			}
			$sessId = array_pop($extra);
			$serverId = array_pop($extra);
			if ($sessId === '' || $serverId === '' || strpos($sessId, '.') !== false || strpos($serverId, '.') !== false) {
				return $this->callMethod('NotFound', $req, $upstream);	
			}
		}
		$req->attrs->sessId = $sessId;
		$req->attrs->serverId = $serverId;
		$req->attrs->path = $path;
		$req = $this->callMethod($method, $req, $upstream);
		if ($req instanceof Methods\Iframe && strlen($version)) {
			$req->attrs->version = $version;
		}
		return $req;
	}

	/**
	 * callMethod
	 * @param  string $method   [@todo description]
	 * @param  object $req      [@todo description]
	 * @param  object $upstream [@todo description]
	 * @return object
	 */
	public function callMethod($method, $req, $upstream) {
		$method = strtr(ucwords(strtr($method, ['_' => ' '])), [' ' => '']);
		if (strtolower($method) === 'generic') {
			$method = 'NotFound';
		}
		$class = __NAMESPACE__ . '\\Methods\\' . $method;
		if (!class_exists($class)) {
			$class = __NAMESPACE__ . '\\Methods\\NotFound'; 
		}
		return new $class($this, $upstream, $req);
	}
}
