<?php

/**
 * Web socket route
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class WebSocketRoute {
	
	public $client; // Remote client
	public $appInstance;

	/**
	 * Called when client connected.
	 * @param object Remote client (WebSocketSession).
	 * @return void
	 */
	public function __construct($client, $appInstance = null) {
		$this->client = $client;

		if ($appInstance) {
			$this->appInstance = $appInstance;
		}
	}

	/**
	 * Called when the connection is handshaked.
	 * @return void
	 */
	public function onHandshake() {}

	/**
	 * Called when session finished.
	 * @return void
	 */
	public function onFinish() {}

	/**
	 * Called when the worker is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function gracefulShutdown() {
		return TRUE;
	}
}
