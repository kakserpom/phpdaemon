<?php

/**
 * PubSubEvent
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

class PubSubEvent extends SplObjectStorage {
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
	 * @return object
	 */
	public function __construct() {
		$this->storage = new SplObjectStorage;
	}

	/**
	 * Sets onActivation callback.
	 * @param callable Callback
	 * @return PubSubEvent
	 */
	public function onActivation($cb) {
		$this->actCb = $cb;
		return $this;
	}

	/**
	 * Sets onDeactivation callback.
	 * @param callable Callback
	 * @return PubSubEvent
	 */
	public function onDeactivation($cb) {
		$this->deactCb = $cb;
		return $this;
	}

	/**
	 * Constructor
	 * @return PubSubEvent
	 */
	public static function init() {
		return new static;
	}

	/**
	 * Subscribe
	 * @param object Subcriber object
	 * @param callable Callback
	 * @return PubSubEvent
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
	 * @param object Subscriber object
	 * @return PubSubEvent
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
	 * @param mixed Data
	 * @return PubSubEvent
	 */
	public function pub($data) {
		foreach ($this as $obj) {
			$cb = $this->getInfo();
			call_user_func($cb, $data);
		}
		return $this;
	}
}
