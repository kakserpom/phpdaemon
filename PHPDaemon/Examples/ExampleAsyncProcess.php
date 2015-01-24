<?php
namespace PHPDaemon\Examples;

/**
 * @package    Examples
 * @subpackage AsyncProcess
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
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