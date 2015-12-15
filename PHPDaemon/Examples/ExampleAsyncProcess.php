<?php
namespace PHPDaemon\Examples;

/**
 * @package    Examples
 * @subpackage AsyncProcess
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class ExampleAsyncProcess extends \PHPDaemon\Core\AppInstance {

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return ExampleAsyncProcessRequest Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleAsyncProcessRequest($this, $upstream, $req);
	}
}