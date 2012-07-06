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
		$onResponse = array();		
		while (true) {
			if (($e = end($args)) &&
			(is_array($e) || is_object($e)) &&
			is_callable($e)) {
				$onResponse[] = array_pop($args);
			} else {
				break;
			}
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
					$this->subscribeCb[$arg] = array_merge($this->subscribeCb[$arg], $onResponse);
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

class RedisClientConnection extends NetworkClientConnection {

	public $result = null;      	// current result (array)
	public $resultLength = 0;
	public $resultSize = 0;			// number of received array items in result
	public $value = '';
	public $valueLength = 0;        // length of incoming value
	public $valueSize = 0;         // size of received part of the value
	public $error;                 // error message
	public $key;                   // current incoming key
	public $EOL = "\r\n";			// EOL for gets() and writeln()
	const STATE_BINARY = 1;

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	*/
	public function stdin($buf) {
		$this->buf .= $buf;
		start:
		if (($this->result !== null) && ($this->resultSize >= $this->resultLength)) {
			$f = $this->onResponse->shift();
			if (!$this->finished && $this->onResponse->isEmpty()) {
				$this->pool->servConnFree[$this->addr][$this->id] = $this->id;
			}

			if ($f) {
				foreach ($f as $cb ) {
					call_user_func($cb, $this);
				}
			}
			
			$this->resultSize = 0;
			$this->resultLength = 0;
			$this->result = null;
			$this->error = false;
		}
		
		if ($this->state === self::STATE_ROOT) { // outside of packet
			while (($l = $this->gets()) !== false) {
				$l = binarySubstr($l, 0, -2); // rtrim \r\n
				$char = $l[0];
				if ($char == ':') { // inline integer
					if ($this->result !== null) {
						++$this->resultSize;
						$this->result[] = (int) binarySubstr($l, 1);
					} else {
						$this->resultLength = 1;
						$this->resultSize = 1;
						$this->result = array((int) binarySubstr($l, 1));
					}
					goto start;
				}
				elseif (($char == '+') || ($char == '-')) { // inline string
					$this->resultLength = 1;
					$this->resultSize = 1;
					$this->error = ($char == '-');
					$this->result = array(binarySubstr($l, 1));
					goto start;
				}
				elseif ($char == '*') { // defines number of elements of incoming array
					$this->resultLength = (int) substr($l, 1);
					$this->resultSize = 0;
					$this->result = array();
					goto start;
				}
				elseif ($char == '$') { // defines size of the data block
					$this->valueLength = (int) substr($l, 1);
					$this->state = self::STATE_BINARY; // binary data block
					break; // stop reading line-by-line
				}
			}
		}

		if ($this->state === self::STATE_BINARY) { // inside of binary data block
			if ($this->valueSize < $this->valueLength) {
				$n = $this->valueLength - $this->valueSize;
				$buflen = strlen($this->buf);

				if ($buflen > $n + 2) {
					$this->value .= binarySubstr($this->buf, 0, $n);
					$this->buf = binarySubstr($this->buf, $n + 2);
				} else {
					$n = min($n, $buflen);
					$this->value .= binarySubstr($this->buf, 0, $n);
					$this->buf = '';
				}

				$this->valueSize += $n;

				if ($this->valueSize >= $this->valueLength) { // we have the value
					$this->state = self::STATE_ROOT;
					++$this->resultSize;
					$this->result[] = $this->value;
					$this->value = '';
					$this->valueSize = 0;
					goto start;
				}
			}
		}
	}	
}
