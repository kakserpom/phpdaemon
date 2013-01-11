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
		$conn->buffer = $oldConn->buffer;
		$conn->fd = $oldConn->fd;
		unset($oldConn->buffer); // to prevent freeing the buffer
		unset($oldConn->fd); // to prevent closing the socket
		$pool->detach($oldConn);
		$set = bufferevent_setcb($conn->buffer, 
			array($conn, 'onReadEvent'),
			array($conn, 'onWriteEvent'),
			array($conn, 'onStateEvent')
		);
		$conn->addr = $req->attrs->server['REMOTE_ADDR'];
		$conn->server = $req->attrs->server;
		$conn->firstline = true;
		$conn->onInheritanceFromRequest($req);
	}
}
