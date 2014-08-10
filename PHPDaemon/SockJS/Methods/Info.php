<?php
namespace PHPDaemon\SockJS\Methods;
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

	protected $contentType = 'application/json';

	public function init() {
		parent::init();
		Crypt::randomInts32(1, function($ints) {
			$opts = [
				'websocket' => true,
				'origins' => ['*:*'],
				'cookie_needed' => false,
				'entropy' => $ints[0],
			];
			if ($o = $this->appInstance->getRouteOptions($this->path)) {
				foreach ($o as $k => $v) {
					if ($k === 'entropy') {
						continue;
					}
					$opts[$k] = $v;
				}
			}
			echo json_encode($opts);
			$this->finish();
		}, 9);
		$this->sleep(5, true);
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		$this->header('500 Server Too Busy');
	}

}
