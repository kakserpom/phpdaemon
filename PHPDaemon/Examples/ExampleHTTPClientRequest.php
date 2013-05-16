<?php
namespace PHPDaemon\Examples;

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

		$this->appInstance->httpclient->post(
			['https://phpdaemon.net/Example/', 'foo' => 'bar'], ['postField' => 'value'],
			function ($conn, $success) {
				echo $conn->body;
				\PHPDaemon\Core\Daemon::$req->finish();
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