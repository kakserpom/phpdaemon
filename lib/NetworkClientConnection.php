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

	public function __construct($fd, $pool = null) {
		parent::__construct($fd, $pool);
		$this->onResponse = new StackCallbacks;
	}

	public function setFree($isFree = true) {
		$this->busy = !$isFree;
		if ($this->busy) {
			$this->pool->servConnFree[$this->url]->detach($this);
		}
		else {
			$this->pool->servConnFree[$this->url]->attach($this);
		}
	}

	public function release() {
		if ($this->pool && !$this->busy) {
			$this->pool->touchPending($this->url);
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
		$this->onResponse->executeAll($this, false);
		unset($this->onResponse);
		if (!$this->pool || !$this->url) {
			return;
		}
		$this->pool->servConnFree[$this->url]->detach($this);
		$this->pool->servConn[$this->url]->detach($this);
		$this->checkFree();
	}

}
