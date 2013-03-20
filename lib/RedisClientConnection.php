<?php

/**
 * @package NetworkClients
 * @subpackage RedisClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

class RedisClientConnection extends NetworkClientConnection {

	/**
	 * Current result
	 * @var array|null
	 */
	public $result = null; 

	/**
	 * Current error message
	 * @var string
	 */
	public $error;
	
	/**
	 * Current incoming key
	 * @var string
	 */
	protected $key;

	/**
	 * Current result length
	 * @var integer
	 */
	protected $resultLength = 0;

	/**
	 * EOL
	 * @var string "\r\n"
	 */
	protected $EOL = "\r\n";

	/**
	 * No Send-and-Forget?
	 * @var boolean
	 */
	protected $noSAF = true;

	/**
	 * In the middle of binary response part
	 * @const integer
	 */
	const STATE_BINARY = 1;

	/**
	 * Check if arrived data is message from subscription
	 */
	protected function isSubMessage() {
		if (sizeof($this->result) < 3) {
			return false;
		}
		$mtype = strtolower($this->result[0]);
		if ($mtype !== 'message' && $mtype !== 'pmessage') {
			return false;
		}
		return true;
	}


	/**
	 * Called when new data received
	 * @return void
	*/
	protected function onRead() {
		start:
		if (($this->result !== null) && (sizeof($this->result) >= $this->resultLength)) {
			if ($this->isSubMessage()) { // sub callback
				$pchan = $this->result[1];
				$sub_cbs = $this->pool->subscribeCb;
				if (in_array($pchan, array_keys($sub_cbs))) {
					$cbs = $sub_cbs[$pchan];
					foreach ($cbs as $cb) {
						if (is_callable($cb)) {
							call_user_func($cb, $this);
						}
					}
				}
			}
			else { // request callback
				$this->onResponse->executeOne($this);
			}
			$this->checkFree();			
			$this->resultLength = 0;
			$this->result = null;
			$this->error = false;
		}
		
		if ($this->state === self::STATE_ROOT) { // outside of packet
			while (($l = $this->readline()) !== null) {
				if ($l === '') {
					continue;
				}
				$char = $l{0};
				if ($char === ':') { // inline integer
					if ($this->result !== null) {
						$this->result[] = (int) binarySubstr($l, 1);
					} else {
						$this->resultLength = 1;
						$this->result = [(int) binarySubstr($l, 1)];
					}
					goto start;
				}
				elseif (($char === '+') || ($char === '-')) { // inline string
					$this->resultLength = 1;
					$this->error = ($char === '-');
					$this->result = [binarySubstr($l, 1)];
					goto start;
				}
				elseif ($char == '*') { // defines number of elements of incoming array
					$this->resultLength = (int) substr($l, 1);
					$this->result = [];
					goto start;
				}
				elseif ($char == '$') { // defines size of the data block
					$this->valueLength = (int) substr($l, 1);
					$this->setWatermark($this->valueLength);
					$this->state = self::STATE_BINARY; // binary data block
					break; // stop reading line-by-line
				}
			}
		}

		if ($this->state === self::STATE_BINARY) { // inside of binary data block
			if (false === ($value = $this->readExact($this->valueLength))) {
				return; //we do not have a whole packet
			}
			$this->state = self::STATE_ROOT;
			$this->setWatermark(0);
			$this->result[] = $value;
			goto start;
		}
	}	
}
