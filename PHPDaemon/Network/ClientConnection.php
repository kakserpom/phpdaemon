<?php
namespace PHPDaemon\Network;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Network\Connection;
use PHPDaemon\Structures\StackCallbacks;

/**
 * Network client connection pattern
 * @package PHPDaemon\Network
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class ClientConnection extends Connection {

	/**
	 * @var boolean Busy?
	 */
	protected $busy = false;
	
	/**
	 * @var boolean Acquired?
	 */
	protected $acquired = false;

	/**
	 * @var integer Timeout
	 */
	protected $timeout = 60;

	/**
	 * @var boolean No Send-and-Forget?
	 */
	protected $noSAF = true;

	/**
	 * @var \PHPDaemon\Structures\StackCallbacks Stack of onResponse callbacks
	 */
	protected $onResponse;

	protected $maxQueue = 1;

	/**
	 * Constructor
	 * @param resource $fd   File descriptor
	 * @param mixed    $pool ConnectionPool
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
	 * @param  callable $cb Callback
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
	 * @param  boolean $free Free?
	 * @return void
	 */
	public function setFree($free = true) {
		if ($this->busy === !$free) {
			return;
		}
		$this->busy = !$free;
		if ($this->url === null) {
			return;
		}
		if ($this->pool === null) {
			return;
		}
		if ($this->finished) {
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
	 * Release the connection
	 * @return void
	 */
	public function release() {
		$this->acquired = false;
		$this->checkFree();
	}
	
	/**
	 * Acquire the connection
	 * @return void
	 */
	public function acquire() {
		$this->acquired = true;
		$this->checkFree();
	}

	/**
	 * Set connection free/busy according to onResponse emptiness and ->finished
	 * @return void
	 */
	public function checkFree() {
		$this->setFree(!$this->finished && !$this->acquired && (!$this->onResponse || $this->onResponse->count() < $this->maxQueue));
	}

	/**
	 * getQueueLength
	 * @return integer
	 */
	public function getQueueLength() {
		return $this->onResponse->count();
	}

	/**
	 * isQueueEmpty
	 * @return boolean
	 */
	public function isQueueEmpty() {
		return $this->onResponse->count() === 0;
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
