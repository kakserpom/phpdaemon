<?php

/**
 * CappedCacheStorage
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
abstract class CappedCacheStorage {	
	public $sorter;
	public $maxCacheSize = 64;
	public $capWindow = 1;
	public $cache = array();
	
	public function __construct() {}
	
	public function hash($key) {
		return crc32($key);
	}
	public function put($key, $value) {
		$k = $this->hash($key);
		$item = new CacheItem($value);
		$this->cache[$k] = $item;
		$s = sizeof($this->cache);
		if ($s > $this->maxCacheSize + $this->capWindow) {
			uasort($this->cache, $this->sorter);
			for (;$s > $this->maxCacheSize; --$s) {
				array_pop($this->cache);
			}
		}
		return $item;
	}
	
	public function invalidate($key) {
		Daemon::log('invalidate('.$key.')');
		$k = $this->hash($key);
		unset($this->cache[$k]);
	}
	
	public function get($key) {
		$k = $this->hash($key);
		if (!isset($this->cache[$k])) {
			return null;
		}
		return $this->cache[$k];
	}
	public function getValue($key) {
		$k = $this->hash($key);
		if (!isset($this->cache[$k])) {
			return null;
		}
		$item = $this->cache[$k];
		return $item->getValue();
	}
}
