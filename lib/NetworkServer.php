<?php

/**
 * Network server pattern
 * @extends ConnectionPool
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class NetworkServer extends ConnectionPool {

	/**
	 * Called when a request to HTTP-server looks like WebSocket handshake query.
	 * @return void
	 */

	public function inheritFromRequest($req, $pool) {
		$oldConn = $req->upstream;
		if ($req instanceof Request) {
			$req->free();
		}
		if (!$oldConn) {
			return false;
		}
		$class = $this->connectionClass;
		$conn = new $class(null, $this);
		$conn->fd = $oldConn->fd;
		$this->attach($conn);
		$conn->bev = $oldConn->bev;
		$conn->fd = $oldConn->fd;
		$oldConn->bev = null; // to prevent freeing the buffer
		$oldConn->fd = null; // to prevent closing the socket
		$pool->detach($oldConn);
		$conn->bev->setCallbacks([$conn, 'onReadEvent'], [$conn, 'onWriteEvent'], [$conn, 'onStateEvent']);
		$conn->addr = $req->attrs->server['REMOTE_ADDR'];
		$conn->server = $req->attrs->server;
		$conn->firstline = true;
		$conn->onInheritanceFromRequest($req);
	}
}
