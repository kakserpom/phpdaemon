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
class NotFound extends Generic {
	protected $contentType = 'text/plain';

	/**
	 * Constructor
	 * @return void
	 */
	public function init() {
		$this->header('404 Not Found');
		echo 'Not found';
		$this->finish();
	}

	/**
	 * Called when request iterated
	 * @return void
	 */
	public function run() {}
}
