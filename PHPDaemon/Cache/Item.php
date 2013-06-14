<?php
namespace PHPDaemon\Cache;

use PHPDaemon\Structures\StackCallbacks;

/**
 * Item
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class Item {

	/** Value
	 * @var mixed
	 */
	protected $value;

	/** Listeners
	 * @var StackCallbacks
	 */
	protected $listeners;

	/** Hits counter
	 * @var integer
	 */
	public $hits = 1;

	/** Expire time
	 * @var integer
	 */
	public $expire;

	/** Establish TCP connection
	 * @return boolean Success
	 */
	public function __construct($value) {
		$this->listeners = new StackCallbacks;
		$this->value     = $value;
	}

	public function getHits() {
		return $this->hits;
	}

	public function getValue() {
		++$this->hits;
		return $this->value;
	}

	/**
	 * @param callable $cb
	 */
	public function addListener($cb) {
		$this->listeners->push($cb);
	}

	public function setValue($value) {
		$this->value = $value;
		$this->listeners->executeAll($this->value);
	}
}
