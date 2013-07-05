<?php
namespace PHPDaemon\Cache;

/**
 * CappedStorageHits
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class CappedStorageHits extends CappedStorage {

	/**
	 * Constructor
	 * @param [integer Maximum number of cached elements]
	 * @return object
	 */
	public function __construct($max = null) {
		if ($max !== null) {
			$this->maxCacheSize = $max;
		}
		$this->sorter = function ($a, $b) {
			if ($a->hits === $b->hits) {
				return 0;
			}
			return ($a->hits < $b->hits) ? 1 : -1;
		};
	}
}
