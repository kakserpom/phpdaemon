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
	
	public function checkFree() {
		if ((sizeof($this->onResponse) > 0) || $this->finished) {
			unset($this->pool->servConnFree[$this->addr][$this->connId]);
		} else {
			$this->pool->servConnFree[$this->addr][$this->connId] = $this->connId;
		}
	}
	/**
	 * Called when session finishes
	 * @return void
	 */
	public function onFinish() {
		parent::onFinish();
		$this->onResponse = array();
		$this->checkFree();
	}

}
