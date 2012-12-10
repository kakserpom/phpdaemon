<?php

/**
 * @package Examples
 * @subpackage ExampleHTTPClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleHTTPClient extends AppInstance {
	public $httpclient;
	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->httpclient = HTTPClient::getInstance();
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
	public function onShutdown() {
		// Finalization.
		return TRUE;
	}

	/** 
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleHTTPClientRequest($this, $upstream, $req);
	}
	
}

class ExampleHTTPClientRequest extends HTTPRequest {

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {

		try {$this->header('Content-Type: text/html');} catch (Exception $e) {}

			$this->appInstance->httpclient->post(
				['http://phpdaemon.net/Example/', 'foo' => 'bar'], ['postField' => 'value'],
				function($conn, $success) {
					echo $conn->body;
					Daemon::$req->finish();
				}
			);
		
		$this->sleep(5, true); // setting timeout
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {		
		echo 'Something went wrong.';
	}
	
}
