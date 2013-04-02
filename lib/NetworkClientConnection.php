<?php

/**
 * Network client connection pattern
 * @extends Connection
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class NetworkClientConnection extends Connection {

	/**
	 * Busy?
	 * @var boolean
	 */
	protected $busy = false;

	/**
	 * Timeout
	 * @var integer
	 */
	protected $timeout = 60;

	/**
	 * No Send-and-Forget?
	 * @var boolean
	 */
	protected $noSAF = true;

	/**
	 * Stack of onResponse callbacks
	 * @var StackCallbacks
	 */
	protected $onResponse;

	/**
	 * Constructor
	 * @param mixed File descriptor
	 * @param [ConnectionPool
	 * @return objectg
	 */
	public function __construct($fd, $pool = null) {
		parent::__construct($fd, $pool);
		$this->onResponse = new StackCallbacks;
	}

	/**
	 * Busy?
	 * @return boolean
	 */
	public function isBusy() {
		return $this->busy;
	}

	/**
	 * Push callback to onReponse stack
	 * @return void
	 */
	public function onResponse($cb) {
		$this->onResponse->push($cb);
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


	/**
	 * Set connection free/busy
	 * @param boolean Free?
	 * @return void
	 */
	public function setFree($free = true) {
		$this->busy = !$free;
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

	/**
	 * Set connection free
	 * @return void
	 */
	public function release() {
		$this->setFree(true);
	}

	/**
	 * Set connection free/busy according to onResponse emptiness and ->finished
	 * @return void
	 */
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
