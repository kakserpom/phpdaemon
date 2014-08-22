<?php
namespace PHPDaemon\Cache;

/**
 * CappedStorage
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
abstract class CappedStorage {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

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
	 * Sets cache size
	 * @param integer Maximum number of elements.
	 * @return void
	 */
	public function setMaxCacheSize($size) {
		$this->maxCacheSize = $size;
	}


	/**
	 * Sets cap window
	 * @param integer
	 * @return void
	 */
	public function setCapWindow($w) {
		$this->capWindow = $w;
	}

	/**
	 * Hash function
	 * @param string Key
	 * @return integer
	 */
	public function hash($key) {
		return crc32($key);
	}

	/**
	 * Puts element in cache
	 * @param string Key
	 * @param mixed  Value
	 * @param [integer Time-to-Life]
	 * @param string $key
	 * @return mixed
	 */
	public function put($key, $value, $ttl = null) {
		$k = $this->hash($key);
		if (isset($this->cache[$k])) {
			$item = $this->cache[$k];
			$item->setValue($value);
			if ($ttl !== null) {
				$item->expire = microtime(true) + $ttl;
			}
			return $item;
		}
		$item = new Item($value);
		if ($ttl !== null) {
			$item->expire = microtime(true) + $ttl;
		}
		$this->cache[$k] = $item;
		$s               = sizeof($this->cache);
		if ($s > $this->maxCacheSize + $this->capWindow) {
			uasort($this->cache, $this->sorter);
			for (; $s > $this->maxCacheSize; --$s) {
				array_pop($this->cache);
			}
		}
		return $item;
	}

	/**
	 * Invalidates cache element
	 * @param string Key
	 * @param string $key
	 * @return void
	 */
	public function invalidate($key) {
		$k = $this->hash($key);
		unset($this->cache[$k]);
	}

	/**
	 * Gets element by key
	 * @param string Key
	 * @param string $key
	 * @return object Item
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
	 * @param string $key
	 * @return mixed
	 */
	public function getValue($key) {
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
		return $item->getValue();
	}
}
