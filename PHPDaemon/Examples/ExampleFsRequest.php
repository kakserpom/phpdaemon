<?php
namespace PHPDaemon\Examples;

use PHPDaemon\HTTPRequest\Generic;

class ExampleFsRequest extends Generic {

	public function init() {
		$req = $this;
		$this->sleep(1, true);
		\PHPDaemon\FS\FileSystem::readfile('/etc/filesystems', function ($file, $data) use ($req) {
			$req->fileData = $data;
			$req->wakeup();
		});
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		$this->header('Content-Type: text/plain');
		echo "Contents of /etc/filesystems:\n" . $this->fileData;
	}

}