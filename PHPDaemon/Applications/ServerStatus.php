<?php
namespace PHPDaemon\Applications;

class ServerStatus extends \PHPDaemon\Core\AppInstance {

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ServerStatusRequest($this, $upstream, $req);
	}

}
