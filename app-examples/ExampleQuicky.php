<?php

/**
 * @package Examples
 * @subpackage Base
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleQuicky extends AppInstance {

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * Uncomment and return array with your default options
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return false;
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
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
		return new ExampleQuickyRequest($this, $upstream, $req);
	}
}

class ExampleQuickyRequest extends HTTPRequest {
	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		$cwd = getcwd();
		chdir('/home/web/quicky/_test/');
		$this->header('Content-Type: text/html');
		include '/home/web/quicky/_test/misc.php';
		chdir($cwd);
	}
	
}
