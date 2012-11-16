<?php

/**
 * Firewall
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Firewall extends AppInstance {
		/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			''
		);
	}


}
class TokenBucket {
	public $max;
	public $time;
	public $qty;
	public function __construct($max, $interval, $maxburst) {
		$this->qty = $qty;
		$this->interval = $interval;
		$this->maxburst = $maxburst;
	}
	public function fill() {

	}
}
