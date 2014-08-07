<?php
namespace PHPDaemon\SockJS;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Utils\Crypt;
/**
 * @package    Blamper
 * @subpackage DNode
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */

class InfoRequest extends Generic {

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		$this->header('Content-Type: application/json; charset=UTF-8');
		Crypt::randomInts(1, function($ints) {
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
