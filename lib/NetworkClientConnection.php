<?php

/**
 * Network client connection pattern
 * @extends Connection
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class NetworkClientConnection extends Connection {
	protected $busy = false;
	protected $timeout = 60;
	protected $noSAF = true;
	protected $onResponse;  // stack of onResponse callbacks
	protected $alive = true;

	public function __construct($fd, $pool = null) {
		parent::__construct($fd, $pool);
		$this->onResponse = new StackCallbacks;
	}

	public function isBusy() {
		return $this->busy;
	}

	public function onResponse($m) {
		$this->onResponse->push($m);
	}
	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		parent::onReady();
		if ($this->url === null) {
			return;
		}
		if ($this->connected && !$this->busy) {
			$this->pool->markConnFree($this, $this->url);
		}
	}

	public function setFree($bool = true) {
		$this->busy = !$bool;
		if ($this->url === null) {
			return;
		}
		if ($this->pool === null) {
			return;
		}
		if ($this->busy) {
			$this->pool->markConnBusy($this, $this->url);
		}
		else {
			$this->pool->markConnFree($this, $this->url);
			$this->pool->touchPending($this->url);
		}
	}

	public function release() {
		$this->setFree(true);
	}

	public function checkFree() {
		$this->setFree(!$this->finished && $this->onResponse && $this->onResponse->isEmpty());
	}

	/**
	 * Called when connection finishes
	 * @return void
	 */
	public function onFinish() {
		$this->onResponse->executeAll($this, false);
		unset($this->onResponse);
		if ($this->pool && ($this->url !== null)) {
			$this->pool->detachConnFromUrl($this, $this->url);
		}
		parent::onFinish();
	}

}
