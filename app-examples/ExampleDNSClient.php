<?php

/**
 * @package Examples
 * @subpackage ExampleHTTPClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleDNSClient extends AppInstance {
	/** 
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleDNSClientRequest($this, $upstream, $req);
	}
	
}

class ExampleDNSClientRequest extends HTTPRequest {

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
		
		$job('query', function($name, $job) { // registering job named 'showvar'
			DNSClient::getInstance()->get('phpdaemon.net', function($response) use ($name, $job) {
				$job->setResult($name, $response);
			});
		});

		$job('resolve', function($name, $job) { // registering job named 'showvar'
			DNSClient::getInstance()->resolve('phpdaemon.net', function($ip) use ($name, $job) {
				$job->setResult($name, array('phpdaemon.net resolved to' =>  $ip));
			});
		});
		
		$job(); // let the fun begin
		
		$this->sleep(5, true); // setting timeout
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {		
		try {$this->header('Content-Type: text/plain');} catch (Exception $e) {}
		var_dump($this->job->getResult('query'));
		var_dump($this->job->getResult('resolve'));
	}
	
}
