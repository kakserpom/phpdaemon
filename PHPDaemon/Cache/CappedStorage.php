<?php
namespace PHPDaemon\Cache;

/**
 * CappedStorage
 * @package PHPDaemon\Cache
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
abstract class CappedStorage {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var callable Sorter function
	 */
	public $sorter;

	/**
	 * @var integer Maximum number of cached elements
	 */
	public $maxCacheSize = 64;

	/**
	 * @var integer Additional window to decrease number of sorter calls
	 */
	public $capWindow = 16;

	/**
	 * @var array Storage of cached items
	 */
	public $cache = [];

	/**
	 * Sets cache size
	 * @param  integer $size Maximum number of elements
	 * @return void
	 */
	public function setMaxCacheSize($size) {
		$this->maxCacheSize = $size;
	}

	/**
	 * Sets cap window
	 * @param  integer $w Additional window to decrease number of sorter calls
	 * @return void
	 */
	public function setCapWindow($w) {
		$this->capWindow = $w;
	}

	/**
	 * Hash function
	 * @param  string $key Key
	 * @return integer
	 */
	public function hash($key) {
		return $key;
	}

	/**
	 * Puts element in cache
	 * @param  string  $key   Key
	 * @param  mixed   $value Value
	 * @param  integer $ttl   Time to live
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
	 * @param  string $key Key
	 * @return void
	 */
	public function invalidate($key) {
		$k = $this->hash($key);
		unset($this->cache[$k]);
	}

	/**
	 * Gets element by key
	 * @param  string $key Key
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
	 * @param  string $key Key
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
