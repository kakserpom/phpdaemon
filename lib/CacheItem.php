<?php

/**
 * CacheItem
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class CacheItem {
	public $value;
	public $hits = 1;
	public $listeners;
	public $expire;
	public function __construct($value) {
		$this->listeners = new SplStack;
		$this->value = $value;
	}
	
	public function getValue() {
		++$this->hits;
		return $this->value;
	}

	public function addListener($cb) {
		$this->listeners->push($cb);
	}

	public function setValue($value) {
		$this->value = $value;
		while (!$this->listeners->isEmpty()) {
			call_user_func($this->listeners->pop(), $this->value);
		}
	}
}

