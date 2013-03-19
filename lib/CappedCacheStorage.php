<?php

/**
 * CappedCacheStorage
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
abstract class CappedCacheStorage {	
	/**
	 * Sorter function
	 * @var callable
	 */
	public $sorter;

	/**
	 * Maximum number of cached elements
	 * @var integer
	 */
	public $maxCacheSize = 64;

	/**
	 * Additional window to decrease number of sorter calls.
	 * @var integer
	 */
	public $capWindow = 16;

	/**
	 * Storage of cached items
	 * @var array
	 */
	public $cache = [];	

	/**
	 * Hash function
	 * @param string Key
	 * @return mixed
	 */
	public function hash($key) {
		return crc32($key);
	}

	/**
	 * Puts element in cache
	 * @param string Key
	 * @param mixed Value
	 * @param [integer Time-to-Life]
	 * @return mixed
	 */
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
	
	/**
	 * Invalidates cache element
	 * @param string Key
	 * @return void
	 */
	public function invalidate($key) {
		$k = $this->hash($key);
		unset($this->cache[$k]);
	}
	
	/**
	 * Gets element by key
	 * @param string Key
	 * @return object CacheItem
	 */
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

	/**
	 * Gets value of element by key
	 * @param string Key
	 * @return mixed
	 */
	public function getValue($key) {
		$k = $this->hash($key);
		if (!isset($this->cache[$k])) {
			return null;
		}
		$item = $this->cache[$k];
		return $item->getValue();
	}
}
