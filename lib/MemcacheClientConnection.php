<?php
/**
 * @package Network clients
 * @subpackage MemcacheClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

class MemcacheClientConnection extends NetworkClientConnection {

	public $result;                // current result
	public $valueFlags;            // flags of incoming value
	public $valueLength;           // length of incoming value
	public $valueSize = 0;         // size of received part of the value
	public $error;                 // error message
	public $key;                   // current incoming key
	const STATE_DATA = 1;
	public $EOL = "\r\n";

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	*/
	public function stdin($buf) {
		$this->buf .= $buf;

		start:

		if ($this->state === self::STATE_ROOT) {
			while (($l = $this->gets()) !== FALSE) {
				$e = explode(' ', rtrim($l, "\r\n"));

				if ($e[0] == 'VALUE') {
					$this->key = $e[1];
					$this->valueFlags = $e[2];
					$this->valueLength = $e[3];
					$this->result = '';
					$this->state = self::STATE_DATA;
					break;
				}
				elseif ($e[0] == 'STAT') {
					if ($this->result === NULL) {
						$this->result = array();
					}

					$this->result[$e[1]] = $e[2];
				}
				elseif (
					($e[0] === 'STORED') 
					|| ($e[0] === 'END') 
					|| ($e[0] === 'DELETED') 
					|| ($e[0] === 'ERROR') 
					|| ($e[0] === 'CLIENT_ERROR') 
					|| ($e[0] === 'SERVER_ERROR')
				) {
					if ($e[0] !== 'END') {
						$this->result = FALSE;
						$this->error = isset($e[1]) ? $e[1] : NULL;
					}

					$this->onResponse->executeOne($this);
					$this->checkFree();

					$this->valueSize = 0;
					$this->result = NULL;
				}
			}
		}

		if ($this->state === self::STATE_DATA) {
			if ($this->valueSize < $this->valueLength) {
				$n = $this->valueLength-$this->valueSize;
				$buflen = strlen($this->buf);

				if ($buflen > $n) {
					$this->result .= binarySubstr($this->buf, 0, $n);
					$this->buf = binarySubstr($this->buf, $n);
				} else {
					$this->result .= $this->buf;
					$n = $buflen;
					$this->buf = '';
				}

				$this->valueSize += $n;

				if ($this->valueSize >= $this->valueLength) {
					$this->state = self::STATE_ROOT;
					goto start;
				}
			}
		}
	}
}
