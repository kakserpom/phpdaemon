<?php
namespace PHPDaemon;

use PHPDaemon\ConnectionPool;
use PHPDaemon\Request\Generic;

/**
 * Network server pattern
 * @extends ConnectionPool
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
abstract class NetworkServer extends ConnectionPool {

	/**
	 * Called when a request to HTTP-server looks like another connection.
	 * @return boolean Success
	 */

	public function inheritFromRequest($req, $oldConn) {
		if (!$oldConn || !$req) {
			return false;
		}
		$class = $this->connectionClass;
		$conn  = new $class(null, $this);
		$this->attach($conn);
		$conn->setFd($oldConn->getFd(), $oldConn->getBev());
		$oldConn->unsetFd();
		$oldConn->pool->detach($oldConn);
		$conn->onInheritanceFromRequest($req);
		if ($req instanceof Generic) {
			$req->free();
		}
		return true;
	}
}
