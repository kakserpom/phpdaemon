<?php

/**
 * @package Examples
 * @subpackage Sandbox
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleSandbox extends AppInstance {

	public $counter = 0;

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
		return new ExampleSandboxRequest($this, $upstream, $req);
	}
	
}

class ExampleSandboxRequest extends HTTPRequest {

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		$stime = microtime(TRUE);
		$this->header('Content-Type: text/html');
 
		$sandbox = new Runkit_Sandbox(array(
			'safe_mode'        => TRUE,
			'open_basedir'     => '/var/www/users/jdoe/',
			'allow_url_fopen'  => 'false',
			'disable_functions'=>'exec,shell_exec,passthru,system',
			'disable_classes'  => '',
			'output_handler'   => array($this,'out')
		));

		$sandbox->ini_set('html_errors',true);
		$sandbox->call_user_func(function() {
			echo "Hello World!";
		});
	}
	
}
