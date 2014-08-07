<?php
namespace PHPDaemon\SockJS;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Utils\Crypt;
/**
 * @package    Libraries
 * @subpackage SockJS
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */

class Info extends Generic {
	use Traits\Request;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->CORS();
		$this->contentType('application/json');
		$this->noncache();
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		Crypt::randomInts32(1, function($ints) {
			echo json_encode([
				'websocket' => true,
				'origins' => ['*:*'],
				'cookie_needed' => false,
				'entropy' => $ints[0],
			]);
			$this->finish();
		}, 9);
		$this->sleep(5);
	}

}
