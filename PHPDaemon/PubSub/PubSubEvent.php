<?php
namespace PHPDaemon\PubSub;

/**
 * PubSubEvent
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */

class PubSubEvent extends \SplObjectStorage {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * Subscriptions
	 * @var array
	 */
	public $sub = [];

	/**
	 * Activation callback
	 * @var callable
	 */
	public $actCb;

	/**
	 * Deactivation callback
	 * @var callable
	 */
	public $deactCb;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->storage = new \SplObjectStorage;
	}

	/**
	 * Sets onActivation callback.
	 * @param callable Callback
	 * @return \PHPDaemon\PubSub\PubSubEvent
	 */
	public function onActivation($cb) {
		$this->actCb = $cb;
		return $this;
	}

	/**
	 * Sets onDeactivation callback.
	 * @param callable Callback
	 * @return \PHPDaemon\PubSub\PubSubEvent
	 */
	public function onDeactivation($cb) {
		$this->deactCb = $cb;
		return $this;
	}

	/**
	 * Constructor
	 * @return \PHPDaemon\PubSub\PubSubEvent
	 */
	public static function init() {
		return new static;
	}

	/**
	 * Subscribe
	 * @param object $obj  Subcriber object
	 * @param callable $cb Callback
	 * @return \PHPDaemon\PubSub\PubSubEvent
	 */
	public function sub($obj, $cb) {
		$act = $this->count() === 0;
		$this->attach($obj, $cb);
		if ($act) {
			if ($this->actCb !== null) {
				call_user_func($this->actCb, $this);
			}
		}
		return $this;
	}

	/**
	 * Unsubscripe
	 * @param object $obj Subscriber object
	 * @return \PHPDaemon\PubSub\PubSubEvent
	 */
	public function unsub($obj) {
		$this->detach($obj);
		if ($this->count() === 0) {
			if ($this->deactCb !== null) {
				call_user_func($this->deactCb, $this);
			}
		}
		return $this;
	}

	/**
	 * Publish
	 * @param mixed $data Data
	 * @return \PHPDaemon\PubSub\PubSubEvent
	 */
	public function pub($data) {
		foreach ($this as $obj) {
			$cb = $this->getInfo();
			call_user_func($cb, $data);
		}
		return $this;
	}
}
