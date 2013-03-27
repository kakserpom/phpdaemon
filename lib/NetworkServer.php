<?php

/**
 * Network server pattern
 * @extends ConnectionPool
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
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
		$conn = new $class(null, $this);
		$this->attach($conn);
		$conn->setFd($oldConn->getFd(), $oldConn->getBev());
		$oldConn->unsetFd();
		$oldConn->pool->detach($oldConn);
		$conn->onInheritanceFromRequest($req);
		if ($req instanceof Request) {
			$req->free();
		}
		return true;
	}
}
