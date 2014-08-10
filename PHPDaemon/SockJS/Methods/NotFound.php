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

class NotFound extends Generic {
	protected $contentType = 'text/plain';
	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->header('404 Not Found');
		echo 'Method not found.';
		$this->finish();
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {}

}
