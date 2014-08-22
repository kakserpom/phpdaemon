<?php
namespace PHPDaemon\Examples;

class ExampleLockClient extends \PHPDaemon\Core\AppInstance {
	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return ExampleLockClientRequest Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleLockClientRequest($this, $upstream, $req);
	}
}
