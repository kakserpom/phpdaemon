<?php
namespace PHPDaemon\Applications;

/**
 * Class ServerStatus
 * @package PHPDaemon\Applications
 */
class ServerStatus extends \PHPDaemon\Core\AppInstance {

	/**
	 * Creates Request.
	 * @param \PHPDaemon\Request\Generic $req               Request.
	 * @param \PHPDaemon\Request\IRequestUpstream $upstream application instance.
	 * @return \PHPDaemon\Request\Generic Request.
	 */
	public function beginRequest($req, $upstream) {
		return new ServerStatusRequest($this, $upstream, $req);
	}

}
