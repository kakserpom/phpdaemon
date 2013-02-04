<?php

/**
 * @package Applications
 * @subpackage RedisClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

class RedisClient extends NetworkClient {
	public $noSAF = true; // Send-And-Forget queries are not present in the protocol
	public $subscribeCb = []; // subscriptions callbacks
	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			// @todo add description strings
			'servers'               =>  '127.0.0.1',
			'port'					=> 6379,
			'maxconnperserv'		=> 32,
		];
	}


	public function __call($name, $args) {
		$onResponse = null;		
		if (($e = end($args)) && (is_array($e) || is_object($e)) &&	is_callable($e)) {
			$onResponse = array_pop($args);
		}
		reset($args);
		$name = strtoupper($name);
		array_unshift($args, $name);
		$s = sizeof($args);
		$r = '*' . $s . "\r\n";
		foreach ($args as $arg) {
			$r .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
		}
		$this->requestByServer($server = null, $r, $onResponse);

		// PUB/SUB handling
		if (($name === 'SUBSCRIBE') || ($name === 'PSUBSCRIBE')) {
			for ($i = 1; $i < $s; ++$i) {
				$arg = $args[$i];
				// TODO: check if $onResponse already in subscribeCb[$arg]?
				$this->subscribeCb[$arg][] = CallbackWrapper::wrap($onResponse);
			}
		}

		if (($name === 'UNSUBSCRIBE') || ($name === 'PUNSUBSCRIBE')) {
			for ($i = 1; $i < $s; ++$i) {
				$arg = $args[$i];
				if (isset($this->subscribeCb[$arg])) {
					unset($this->subscribeCb[$arg]);
				}
			}
		}
	}	
}
