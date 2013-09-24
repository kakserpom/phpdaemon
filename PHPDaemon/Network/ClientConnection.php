<?php
namespace PHPDaemon\Network;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Network\Connection;
use PHPDaemon\Structures\StackCallbacks;

/**
 * Network client connection pattern
 * @extends Connection
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class ClientConnection extends Connection {

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
	 * @var \PHPDaemon\Structures\StackCallbacks
	 */
	protected $onResponse;

	/**
	 * Constructor
	 * @param mixed $fd   File descriptor
	 * @param mixed $pool ConnectionPool
	 */
	public function __construct($fd, $pool = null) {
		parent::__construct($fd, $pool);
		$this->onResponse = new StackCallbacks();
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
		if ($cb === null && !$this->noSAF) {
			return;
		}
		$this->onResponse->push($cb);
		$this->checkFree();
	}

	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		parent::onReady();
		$this->setWatermark(null, $this->pool->maxAllowedPacket);
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
		$this->onResponse = null;
		if ($this->pool && ($this->url !== null)) {
			$this->pool->detachConnFromUrl($this, $this->url);
		}
		parent::onFinish();
	}
}
