<?php

/**
 * PubSubEvent
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

class PubSubEvent extends SplObjectStorage {
	public $sub = array();
	public $actCb;
	public $deactCb;
	public function __construct() {
		$this->storage = new SplObjectStorage;
	}
	public function onActivation($cb) {
		$this->actCb = $cb;
		return $this;
	}
	public function onDeactivation($cb) {
		$this->deactCb = $cb;
		return $this;
	}
	public static function init() {
		$class = get_called_class();
		return new $class;
	}
	public function sub($obj, $cb) {
		$act = $this->count() === 0;
		$this->attach($obj, $cb);
		if ($act) {
			if ($this->actCb !== null) {
				call_user_func($this->actCb, $this);
			}
		}
	}
	public function unsub($obj) {
		$this->detach($obj);
		if ($this->count() === 0) {
			if ($this->deactCb !== null) {
				call_user_func($this->deactCb, $this);
			}
		}
	}
	public function pub($data) {
		foreach ($this as $obj) {
			$cb = $this->getInfo();
			call_user_func($cb, $data);
		}
	}
}
