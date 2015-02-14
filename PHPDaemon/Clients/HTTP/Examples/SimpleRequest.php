<?php
namespace PHPDaemon\Clients\HTTP\Examples;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\HTTPRequest\Generic;

class SimpleRequest extends Generic {
	/**
	 * Constructor
	 * @return void
	 */
	public function init() {
		try {
			$this->header('Content-Type: text/html');
		} catch (\Exception $e) {}

		$this->appInstance->httpclient->get('http://www.google.com/robots.txt',
			function ($conn, $success) {
				echo $conn->body;
				$this->finish();
			}
		);

		// setting timeout
		$this->sleep(5, true);
	}

	/**
	 * Called when request iterated
	 * @return integer Status
	 */
	public function run() {
		echo 'Something went wrong.';
	}
}
