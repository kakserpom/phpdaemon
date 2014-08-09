<?php
namespace PHPDaemon\SockJS\TestRelay;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Structures\ObjectStorage;
use PHPDaemon\Core\Debug;
use PHPDaemon\Servers\WebSocket\Pool as WebSocketPool;
/**
 * @package    SockJS
 * @subpackage TestRelay
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Application extends \PHPDaemon\Core\AppInstance {
	/**
	 * Setting default config options
	 * @return array|bool
	 */
	protected function getConfigDefaults() {
		return [
			'wss-name' => '',
		];
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		$ws = WebSocketPool::getInstance($this->config->wssname->value);
		$ws->addRoute('close', function ($client) {return new Close($client, $this);});
		$ws->addRoute('echo', function ($client) {return new EchoFeed($client, $this);});
		$ws->addRoute('disabled_websocket_echo', function ($client) {return new DisabledWebSocket($client, $this);});
		$ws->addRoute('cookie_needed_echo', function ($client) {return new CookieNeededEcho($client, $this);});
	}
}
