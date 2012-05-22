<?php

/**
 * Network client connection pattern
 * @extends Connection
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class NetworkClientConnection extends Connection {
	
	public $onResponse = array();  // stack of onResponse callbacks
	public $state = 0;             // current state of the connection
	const STATE_ROOT = 0;
	
	/**
	 * Called when session finishes
	 * @return void
	 */
	public function onFinish() {
		parent::onFinish();
		unset($this->pool->servConn[$this->addr][$this->connId]);
		unset($this->pool->servConnFree[$this->addr][$this->connId]);
	}

}
