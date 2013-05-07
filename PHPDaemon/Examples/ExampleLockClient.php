<?php
namespace PHPDaemon\Examples;

class ExampleLockClient extends \PHPDaemon\AppInstance {
	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ExampleLockClientRequest($this, $upstream, $req);
	}
}
