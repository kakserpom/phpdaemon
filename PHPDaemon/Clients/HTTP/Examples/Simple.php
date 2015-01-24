<?php

/**
 * `phpd.conf`
 * Clients\HTTP\Examples\Simple {}
 */
namespace PHPDaemon\Clients\HTTP\Examples;

/**
 * @package    Examples
 * @subpackage ExampleHTTPClient
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class Simple extends \PHPDaemon\Core\AppInstance {
	public $httpclient;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->httpclient = \PHPDaemon\Clients\HTTP\Pool::getInstance();
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */

	public function onReady() {
		// Initialization.
	}

	/**
	 * Called when application instance is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown($graceful = false) {
		// Finalization.
		return TRUE;
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