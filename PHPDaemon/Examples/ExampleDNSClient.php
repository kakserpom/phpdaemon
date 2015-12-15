<?php
namespace PHPDaemon\Examples;

/**
 * @package    Examples
 * @subpackage ExampleHTTPClient
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class ExampleDNSClient extends \PHPDaemon\Core\AppInstance {
	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return ExampleDNSClientRequest Request.
	 */
	public function beginRequest($req, $upstream) {
		return new \PHPDaemon\Examples\ExampleDNSClientRequest($this, $upstream, $req);
	}

}
