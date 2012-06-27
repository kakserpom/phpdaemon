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
	public function __construct($value) {
		$this->value = $value;
	}
	
	public function getValue() {
		++$this->hits;
		return $this->value;
	}
}

