<?php

/**
 * @package Applications
 * @subpackage RedisClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class RedisClient extends NetworkClient {
	public $noSAF = true; // Send-And-Forget queries are not present in the protocol
	public $subscribeCb = array();
	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'servers'               =>  '127.0.0.1',
			'port'					=> 6379,
			'maxconnperserv'		=> 32,
		);
	}


	public function __call($name, $args) {
		$onResponse = null;		
		if (($e = end($args)) && (is_array($e) || is_object($e)) &&	is_callable($e)) {
			$onResponse= array_pop($args);
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
		if (($name === 'SUBSCRIBE') || ($name === 'PSUBSCRIBE')) {
			for ($i = 1; $i < $s; ++$i) {
				$arg = $args[$i];
				if (!isset($this->subscribeCb[$arg])) {
					$this->subscribeCb[$arg] = $onResponse;
				} else {
					$this->subscribeCb[$arg] = array_merge($this->subscribeCb[$arg], array($onResponse));
				}
			}
		}
		if (($name === 'UNSUBSCRIBE') || ($name === 'PUNSUBSCRIBE')) {
			for ($i = 1; $i < $s; ++$i) {
				$arg = $args[$i];
				if (isset($this->subscribeCb[$arg])) {
					$this->subscribeCb[$arg] = $onResponse;
				}
			}
		}
	}	
}
