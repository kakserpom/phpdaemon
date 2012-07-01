<?php

/**
 * @package Examples
 * @subpackage ExampleHTTPClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleHTTPClient extends AppInstance {

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() { }

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

	public $job;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {

		$req = $this;
		
		$job = $this->job = new ComplexJob(function() use ($req) { // called when job is done

			$req->wakeup(); // wake up the request immediately

		});
		
		$job('request', function($name, $job) { // registering job named 'showvar'
			$httpclient = HTTPClient::getInstance();
			$cb = function($conn, $success) use ($name, $job) {
					$job->setResult($name, $conn->body);
			};
			//$httpclient->get(['http://phpdaemon.net/Example/', 'foo' => 'bar'], $cb);
			$httpclient->post(['http://phpdaemon.net/Example/', 'foo' => 'bar'], ['postField' => 'value'] , $cb);
		});
		
		$job(); // let the fun begin
		
		$this->sleep(5, true); // setting timeout
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {		
		try {$this->header('Content-Type: text/html');} catch (Exception $e) {}
		echo $this->job->getResult('request');
	}
	
}
