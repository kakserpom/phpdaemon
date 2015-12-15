<?php
/**
 * `phpd.conf`
 * Clients\HTTP\Examples\Simple {}
 */
namespace PHPDaemon\Clients\HTTP\Examples;

/**
 * @package    NetworkClients
 * @subpackage HTTPClientExample
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Simple extends \PHPDaemon\Core\AppInstance {
	/**
	 * @var Pool
	 */
	public $httpclient;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		$this->httpclient = \PHPDaemon\Clients\HTTP\Pool::getInstance();
	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return SimpleRequest Request.
	 */
	public function beginRequest($req, $upstream) {
		return new SimpleRequest($this, $upstream, $req);
	}
}
