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
class Welcome extends Generic {
	protected $contentType = 'text/plain';
	protected $cacheable = true;

	/**
	 * Constructor
	 * @return void
	 */
	public function init() {
		parent::init();
		$this->header('Cache-Control: max-age=31536000, public, pre-check=0, post-check=0');
		$this->header('Expires: '.date('r', strtotime('+1 year')));
		echo "Welcome to SockJS!\n";
		$this->finish();
	}

	/**
	 * Called when request iterated
	 * @return void
	 */
	public function run() {}
}
