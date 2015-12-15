<?php
namespace PHPDaemon\Cache;

use PHPDaemon\Structures\StackCallbacks;

/**
 * Item
 * @package PHPDaemon\Cache
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class Item {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var mixed Value
	 */
	protected $value;

	/**
	 * @var StackCallbacks Listeners
	 */
	protected $listeners;

	/**
	 * @var integer Hits counter
	 */
	public $hits = 1;

	/**
	 * @var integer Expire time
	 */
	public $expire;

	/**
	 * Constructor
	 */
	public function __construct($value) {
		$this->listeners = new StackCallbacks;
		$this->value     = $value;
	}

	/**
	 * Get hits number
	 * @return integer
	 */
	public function getHits() {
		return $this->hits;
	}

	/**
	 * Get value
	 * @return mixed
	 */
	public function getValue() {
		++$this->hits;
		return $this->value;
	}

	/**
	 * Adds listener callback
	 * @param callable $cb
	 */
	public function addListener($cb) {
		$this->listeners->push($cb);
	}

	/**
	 * Sets the value
	 * @param mixed $value
	 */
	public function setValue($value) {
		$this->value = $value;
		$this->listeners->executeAll($this->value);
	}
}
