<?php
namespace PHPDaemon\WebSocket;

/**
 * Web socket route
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class Route {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var \PHPDaemon\Servers\WebSocket\Connection
	 */
	public $client; // Remote client
	/**
	 * @var \PHPDaemon\Core\AppInstance
	 */
	public $appInstance;

	/**
	 * Called when client connected.
	 * @param \PHPDaemon\Servers\WebSocket\Connection $client Remote client
	 * @param \PHPDaemon\Core\AppInstance $appInstance
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
	public function onHandshake() {
	}

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
	}

	/**
	 * Called when the worker is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function gracefulShutdown() {
		return TRUE;
	}
}
