<?php
namespace PHPDaemon\Examples;

use PHPDaemon\HTTPRequest\Generic;

class ExampleAsyncProcessRequest extends Generic {

	public $proc;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->header('Content-Type: text/plain');

		$this->proc = new \PHPDaemon\Core\ShellCommand();
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
