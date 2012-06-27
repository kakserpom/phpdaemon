<?php

/**
 * CappedCacheStorageHits
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class CappedCacheStorageHits extends CappedCacheStorage {	
	public function __construct($max = null) {
		if ($max !== null) {
			$this->maxCacheSize = $max;
		}
		$this->sorter = function($a, $b) {
			if ($a->hits == $b->hits) {
				return 0;
			}
			return ($a->hits < $b->hits) ? 1 : -1;
		};
	}
}
