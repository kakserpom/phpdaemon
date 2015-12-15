<?php
namespace PHPDaemon\Examples;

/**
 * @package    Examples
 * @subpackage Base
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class ExampleICMP extends \PHPDaemon\Core\AppInstance {
	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return ExampleICMPRequest Request.
	 */
	public function beginRequest($req, $upstream) {
		return new \PHPDaemon\Examples\ExampleICMPRequest($this, $upstream, $req);
	}
}
