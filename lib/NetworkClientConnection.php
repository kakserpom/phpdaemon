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
	public $timeout = 60;
	
	public $onResponse;  // stack of onResponse callbacks
	
	public $alive = true;

	public function __construct($fd, $id = null, $pool = null) {
		parent::__construct($fd, $id, $pool);
		$this->onResponse = new SplStack();
	}	

	
	public function setFree($isFree = true) {
		$this->busy = !$isFree;
		if ($this->busy) {
			unset($this->pool->servConnFree[$this->url][$this->id]);
		}
		else {
			$this->pool->servConnFree[$this->url][$this->id] = $this->id;
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
		unset($this->pool->servConn[$this->url][$this->id]);
		$this->checkFree();
	}

}
