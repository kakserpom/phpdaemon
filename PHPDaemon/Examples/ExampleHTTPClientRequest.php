<?php
namespace PHPDaemon\Examples;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\HTTPRequest\Generic;

class ExampleHTTPClientRequest extends Generic {

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {

		try {
			$this->header('Content-Type: text/html');
		} catch (\Exception $e) {
		}

		$this->appInstance->httpclient->get(
			['http://www.cmyip.com/'],
			function ($conn, $success) {
				echo $conn->body;
				$this->finish();
			}
		);

		$this->sleep(5, true); // setting timeout
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		echo 'Something went wrong.';
	}

}