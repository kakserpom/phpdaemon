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
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Application extends \PHPDaemon\Core\AppInstance {
	protected $redis;
	public $wss;

	protected $sessions;
	/**
	 * Setting default config options
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			'redis-name' => '',
			'redis-prefix' => 'sockjs:',
			'wss-name' => '',
		];
	}


	public function getLocalSubscribersCount($chan) {
		return $this->redis->getLocalSubscribersCount($this->config->redisprefix->value . $chan);
	}

	public function subscribe($chan, $cb, $opcb = null) {
		$this->redis->subscribe($this->config->redisprefix->value . $chan, $cb, $opcb);
	}

	public function unsubscribe($chan, $cb, $opcb = null) {
		$this->redis->unsubscribe($this->config->redisprefix->value . $chan, $cb, $opcb);
	}

	public function publish($chan, $cb, $opcb = null) {
		$this->redis->publish($this->config->redisprefix->value . $chan, $cb, $opcb);
	}

	/**
	 * Called when the worker is ready to go.
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

	public function onFinish() {
		foreach ($this->attachedTo as $wss) {
			$this->detachWss($wss);
		}
		parent::onFinish();
	}

	public function attachWss($wss) {
		if ($this->wss->contains($wss)) {
			return false;
		}
		$this->wss->attach($wss);
		$wss->bind('customTransport', [$this, 'wsHandler']);
		return true;
	}

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

	public function detachWss($wss) {
		if (!$this->wss->contains($wss)) {
			return false;
		}
		$this->wss->detach($wss);
		$wss->unbind('transport', [$this, 'wsHandler']);
		return true;
	}

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

	public function endSession($session) {
		$this->sessions->detach($session);
	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		$e = explode('/', $req->attrs->server['DOCUMENT_URI']);
		$method = array_pop($e);
		$serverId = null;
		$sessId = null;
		if ($method === 'websocket') {
			if ((sizeof($e) > 3) && isset($e[sizeof($e) - 2]) && ctype_digit($e[sizeof($e) - 2])) {
				$sessId = array_pop($e);
				$serverId = array_pop($e);
			}
		} elseif ($method === 'info') {

		} elseif (preg_match('~^iframe(?:-([^/]+))?\.html$~', $method, $m)) {
			$method = 'Iframe';
			$version = isset($m[1]) ? $m[1] : null;
		} else {
			if (sizeof($e) < 2) {
				return false;
			}
			$sessId = array_pop($e);
			$serverId = array_pop($e);
		}
		$path = implode('/', $e);
		$name = strtr(ucwords(strtr($method, ['_' => ' '])), [' ' => '']);
		if (strtolower($name) === 'generic') {
			return false;
		}
		$class = __NAMESPACE__ . '\\Methods\\' . $name;
		if (!class_exists($class)) {
			return false;
		}
		$req = new $class($this, $upstream, $req);
		$req->setSessId($sessId);
		$req->setServerId($serverId);
		$req->setPath($path);
		if ($method === 'Iframe' && $version !== null) {
			$req->setVersion($version);
		}
		return $req;
	}
}
