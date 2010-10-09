<?php

/**
 * @package Examples
 * @subpackage AsyncProcess
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class ExampleAsyncProcess extends AppInstance {

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

		$this->proc = new AsyncProcess;

		$this->proc->onReadData(
			function($stream, $data) {
				$stream->request->out($data);
			}
		);

		$this->proc->onEOF(
			function($stream) {
				$stream->request->wakeup();
			}
		);

		$this->proc->setRequest($this);
		$this->proc->nice(256);
		$this->proc->execute('ls -lia');
		$this->proc->closeWrite();
	}

	public function onAbort() {
		if ($this->proc) {
			$this->proc->close();
		}
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
