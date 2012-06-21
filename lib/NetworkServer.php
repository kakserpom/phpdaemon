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
		
		$connId = $req->attrs->connId;
		unset(Daemon::$process->queue[$req->queueId]);
		$oldConn = $pool->getConnectionById($connId);
		if (!$oldConn) {
			return false;
		}
		$class = $this->connectionClass;
		$conn = new $class($connId, null, $req->attrs->server['REMOTE_ADDR'], $this);
		$this->list[$connId] = $conn;
		$conn->buffer = $oldConn->buffer;
		$conn->fd = $oldConn->fd;
		unset($oldConn->buffer);
		unset($oldConn->fd);
		$pool->removeConnection($connId);
		$set = event_buffer_set_callback(
			$conn->buffer, 
			array($conn, 'onReadEvent'),
			array($conn, 'onWriteEvent'),
			array($conn, 'onFailureEvent')
		);
		$conn->addr = $req->attrs->server['REMOTE_ADDR'];
		$conn->server = $req->attrs->server;
		$conn->firstline = true;
		$conn->onInheritanceFromRequest($req);
	}
}
