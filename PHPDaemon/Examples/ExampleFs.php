<?php
namespace PHPDaemon\Examples;

/**
 * @package    Examples
 * @subpackage Base
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class ExampleFs extends \PHPDaemon\Core\AppInstance {
	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return ExampleFsRequest Request.
	 */
	public function beginRequest($req, $upstream) {
		return new \PHPDaemon\Examples\ExampleFsRequest($this, $upstream, $req);
	}
}
