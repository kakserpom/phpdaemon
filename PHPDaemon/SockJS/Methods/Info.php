<?php
namespace PHPDaemon\SockJS\Methods;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Utils\Crypt;

/**
 * @package    Libraries
 * @subpackage SockJS
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Info extends Generic {
	protected $contentType = 'application/json';

	/**
	 * Constructor
	 * @return void
	 */
	public function init() {
		parent::init();
		Crypt::randomInts32(1, function($ints) {
			$this->opts['entropy'] = $ints[0];
			echo json_encode($this->opts);
			$this->finish();
		}, 9);
		$this->sleep(5, true);
	}

	/**
	 * Called when request iterated
	 * @return void
	 */
	public function run() {
		$this->header('500 Server Too Busy');
	}
}
