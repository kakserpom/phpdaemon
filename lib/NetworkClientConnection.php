<?php

/**
 * Network client connection pattern
 * @extends Connection
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class NetworkClientConnection extends Connection {
	public $busy = false;
	
	public $onResponse;  // stack of onResponse callbacks
	
	public $onConnected = null;

	public function __construct($connId, $res, $addr, $pool = null) {
		parent::__construct($connId, $res, $addr, $pool);
		$this->onResponse = new SplStack();
	}	
	
	/**
	 * Executes the given callback when/if the connection is handshaked
	 * Callback
	 * @return void
	 */
	public function onConnected($cb) {
		if ($this->connected) {
			call_user_func($cb, $this);
		} else {
			$this->onConnected = $cb;
		}
	}
	
	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onConnect() {
		if ($this->onConnected) {
			call_user_func($this->onConnected, $this);
			$this->onConnected = null;
		}
	}
	
	public function setFree($isFree = true) {
		$this->busy = !$isFree;
		if ($this->busy) {
			unset($this->pool->servConnFree[$this->addr][$this->connId]);
		}
		else {
			$this->pool->servConnFree[$this->addr][$this->connId] = $this->connId;
		}
	}

	public function checkFree() {
		$this->setFree(!$this->finished && $this->onResponse && $this->onResponse->isEmpty());
	}
	/**
	 * Called when session finishes
	 * @return void
	 */
	public function onFinish() {
		parent::onFinish();
		unset($this->onResponse);
		$this->checkFree();
	}

}
