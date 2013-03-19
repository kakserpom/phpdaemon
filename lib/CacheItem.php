<?php

/**
 * CacheItem
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class CacheItem {

	/* Value
	 * @var mixed
	 */
	protected $value;

	/* Listeners
	 * @var StackCallbacks
	 */
	protected $listeners;

	/* Hits counter
	 * @var integer
	 */
	public $hits = 1;

	/* Expire time
	 * @var integer
	 */
	public $expire;

	/* Establish TCP connection
	 * @param string Hostname
	 * @param integer Port
	 * @return boolean Success
	 */
	public function __construct($value) {
		$this->listeners = new StackCallbacks;
		$this->value = $value;
	}
	
	/* Establish TCP connection
	 * @param string Hostname
	 * @param integer Port
	 * @return boolean Success
	 */
	public function getHits() {
		return $this->hits;
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
		$this->listeners->executeAll($this->value);
	}
}

