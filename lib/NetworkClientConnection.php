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


	public function __construct($connId, $res, $addr, $pool = null) {
		parent::__construct($connId, $res, $addr, $pool);
		$this->onResponse = new SplStack();
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
