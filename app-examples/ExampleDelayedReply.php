<?php

/**
 * @package Examples
 * @subpackage Base
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleDelayedReply extends AppInstance {

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
		return new ExampleDelayedReplyRequest($this, $upstream, $req);
	}
}

class ExampleDelayedReplyRequest extends HTTPRequest {
	public $status = 0;
	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		if ($this->status == 0) {
			++$this->status;
			$this->sleep(2);
		}
		echo 'Reply ;-)';
	}
	
}
