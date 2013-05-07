<?php
namespace PHPDaemon\Examples;

/**
 * @package    Examples
 * @subpackage Base
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class ExampleICMP extends \PHPDaemon\AppInstance {
	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new \PHPDaemon\Examples\ExampleICMPRequest($this, $upstream, $req);
	}
}
