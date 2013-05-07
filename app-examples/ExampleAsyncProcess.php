<?php

/**
 * @package    Examples
 * @subpackage AsyncProcess
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class ExampleAsyncProcess extends \PHPDaemon\AppInstance {

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleAsyncProcessRequest($this, $upstream, $req);
	}
}

class ExampleAsyncProcessRequest extends HTTPRequest {

	public $proc;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->header('Content-Type: text/plain');

		$this->proc = new \PHPDaemon\AsyncProcess();
		$this->proc->onReadData(function ($stream, $data) {
			echo $data;
		});
		$this->proc->onEOF(function ($stream) {
			$this->wakeup();
		});
		$this->proc->nice(256);
		$this->proc->execute('/bin/ls -l /tmp');
	}

	public function onAbort() {
		if ($this->proc) {
			$this->proc->close();
		}
	}

	public function onFinish() {
		$this->proc = null;
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		if (!$this->proc->eof()) {
			$this->sleep(1);
		}
	}

}
