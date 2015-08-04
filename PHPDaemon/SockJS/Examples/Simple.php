<?php
namespace PHPDaemon\SockJS\Examples;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/*
# phpd.conf

Pool:Servers\HTTP {
	listen "0.0.0.0";
	port 8068;
}
Pool:Servers\WebSocket {
	listen "0.0.0.0";
	port 8069;
}

SockJS\Examples\Simple {}
SockJS\Application {}

# AppResolver.php

$uri = $req->attrs->server['DOCUMENT_URI'];

if(strpos($uri, '/sockjspage/') === 0) {
	return 'SockJS\\Examples\\Simple';
}
if(strpos($uri, '/sockjs/') === 0) {
	return 'SockJS\\Application';
}
*/

class Simple extends \PHPDaemon\Core\AppInstance {
	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		\PHPDaemon\Servers\WebSocket\Pool::getInstance()->addRoute('/sockjs',
			function ($client) {
				return new SimpleRoute($client, $this);
			}
		);
	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return SimpleRequest Request.
	 */
	public function beginRequest($req, $upstream) {
		return new SimpleRequest($this, $upstream, $req);
	}
}
