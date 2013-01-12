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
	public $capWindow = 16;
	public $cache = array();	
	public function hash($key) {
		return crc32($key);
	}
	public function put($key, $value, $ttl = null) {
		$k = $this->hash($key);
		if (isset($this->cache[$k])) {
			$item = $this->cache[$k];
			$item->setValue($value);
			return $item;
		}
		$item = new CacheItem($value);
		if ($ttl !== null) {
			$item->expire = microtime(true) + $ttl;
		}
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
		$k = $this->hash($key);
		unset($this->cache[$k]);
	}
	
	public function get($key) {
		$k = $this->hash($key);
		if (!isset($this->cache[$k])) {
			return null;
		}
		$item = $this->cache[$k];
		if (isset($item->expire)) {
			if (microtime(true) >= $item->expire) {
				unset($this->cache[$k]);
				return null;
			}
		}
		return $item;
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
