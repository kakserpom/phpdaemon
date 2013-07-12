<?php
/**
 * @package    Examples
 * @subpackage ExampleGibson
 *
 * @protocol http://gibson-db.in/protocol.php
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
namespace PHPDaemon\Clients\Gibson;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Utils\Binary;

class Connection extends ClientConnection {
	public $error; // error message

	const REPL_ERR = 0x00;              // Generic error while executing the query.
	const REPL_ERR_NOT_FOUND = 0x01;    // Specified key was not found.
	const REPL_ERR_NAN = 0x02;          // Expected a number ( TTL or TIME ) but the specified value was invalid.
	const REPL_ERR_MEM = 0x03;          // The server reached configuration memory limit and will not accept any new value until its freeing routine will be executed.
	const REPL_ERR_LOCKED = 0x04;       // The specificed key was locked by a OP_LOCK or a OP_MLOCK query.
	const REPL_OK = 0x05;               // Query succesfully executed, no data follows.
	const REPL_VAL = 0x06;              // Query succesfully executed, value data follows.
	const REPL_KVAL = 0x07;             // Query succesfully executed, multiple key => value data follows.

	const STATE_PACKET_HDR = 0x01;
	const STATE_PACKET_DATA = 0x02;

	const GB_ENC_PLAIN = 0x00;			//	Raw string data follows.
	const GB_ENC_LZF	= 0x01;			//	Compressed data, this is a reserved value not used for replies.
	const GB_ENC_NUMBER = 0x02;			// Packed long number follows, four bytes for 32bit architectures, eight bytes for 64bit.

	/**
	 * Default low mark. Minimum number of bytes in buffer.
	 * @var integer
	 */
    protected $lowMark = 2;

	public $responseCode;
	public $encoding;
	public $responseLength;
	public $result;
	public $isFinal = false;
	public $totalNum;
	public $readedNum;

	public function onReady() {
		parent::onReady();
	}
	public function isFinal() {
		return $this->isFinal;
	}
	public function getTotalNum() {
		return $this->totalNum;
	}
	public function getReadedNum() {
		return $this->readedNum;
	}
	public function getResponseCode() {
		return $this->responseCode;
	}
	protected function onRead() {
		start:
		if ($this->state === static::STATE_STANDBY) {
			if (($hdr = $this->readExact(2)) === false) {
				return; // not enough data
			}

			$u = unpack('S', $hdr);
			$this->responseCode = $u[1];
			$this->state = static::STATE_PACKET_HDR;
		}
		if ($this->state === static::STATE_PACKET_HDR) {
			if ($this->responseCode === static::REPL_KVAL) {
				$this->result = [];
				if (($hdr = $this->readExact(9)) === false) {
					return; // not enough data
				}
				$this->encoding = Binary::getByte($hdr);
				$this->responseLength = Binary::getDword($hdr, true);
				$this->totalNum = Binary::getDword($hdr, true);
				$this->readedNum = 0;
				$this->state = static::STATE_PACKET_DATA;

			} else {
				if (($hdr = $this->readExact(5)) === false) {
					return; // not enough data
				}
				$this->encoding = Binary::getByte($hdr);
				$this->responseLength = Binary::getDword($hdr, true);
				if ($this->responseCode === static::REPL_ERR_NOT_FOUND) {
					$this->result = null;
					$this->isFinal = true;
					$this->totalNum = 0;
					$this->readedNum = 0;
					$this->executeCb();
				}
				elseif ($this->responseCode === static::REPL_OK) {
					$this->result = true;
					$this->isFinal = true;
					$this->totalNum = 0;
					$this->readedNum = 0;
					$this->executeCb();
				}
				elseif (($this->responseCode === static::REPL_ERR_MEM) ||
						($this->responseCode === static::REPL_ERR_NAN) ||
						($this->responseCode === static::REPL_ERR_LOCKED)) {
					$this->result = false;
					$this->isFinal = true;
					$this->totalNum = 0;
					$this->readedNum = 0;
					$this->executeCb();
				} else {
					if ($this->responseCode === static::REPL_KVAL && $this->totalNum <= 0) {
						$this->isFinal = true;
						$this->totalNum = 0;
						$this->readedNum = 0;
						$this->result = [];
						$this->executeCb();
					} else {
						$this->state = static::STATE_PACKET_DATA;
					}
				}
			}
		}
		if ($this->state === static::STATE_PACKET_DATA) {
			if ($this->responseCode === static::REPL_KVAL) {
				nextElement:
				$l = $this->getInputLength();
				if ($l < 9) {
					goto cursorCall;
				}
				if (($hdr = $this->lookExact($o = 4)) === false) {
					goto cursorCall;
				}
				$keyLen = Binary::getDword($hdr, true);
				if (($key = $this->lookExact($keyLen, $o)) === false) {
					goto cursorCall;
				}
				$o += $keyLen;
				if (($encoding = $this->lookExact(1, $o)) === false) {
					goto cursorCall;
				}
				$encoding = ord($encoding);
				++$o;
				if (($hdr = $this->lookExact(4, $o)) === false) {
					goto cursorCall;
				}
				$o += 4;
				$valLen = Binary::getDword($hdr, true);
				if ($o + $valLen > $l) {
					goto cursorCall;
				}
				$this->drain($o);
				if ($encoding === static::GB_ENC_NUMBER) {
					$val = $this->read($valLen);
					$this->result[$key] = $valLen === 8
											? Binary::getQword($val, true)
											: Binary::getDword($val, true);
				} else {
					$this->result[$key] = $this->read($valLen);
				}
				if (++$this->readedNum >= $this->totalNum) {
					$this->isFinal = true;
					$this->executeCb();
					goto start;
				} else {
					goto nextElement;
				}
				cursorCall:
				$this->onResponse->executeAndKeepOne($this);
				return;
				
			} else {
				if (($this->result = $this->readExact($this->responseLength)) === false) {
					$this->setWatermark($this->responseLength);
					return;
				}
				$this->setWatermark(2);
				if ($this->encoding === static::GB_ENC_NUMBER) {
					$this->result = $this->responseLength === 8
									? Binary::getQword($this->result, true)
									: Binary::getDword($this->result, true);
				}
				$this->isFinal = true;
				$this->totalNum = 1;
				$this->readedNum = 1;
				$this->executeCb();
			}
		}
		goto start;
	}

	protected function executeCb() {
		$this->state = static::STATE_STANDBY;
		$this->onResponse->executeOne($this);
		$this->encoding = null;
		$this->responseLength = null;
		$this->result = null;
		$this->totalNum = null;
		$this->readedNum = null;
		$this->isFinal = false;
		$this->checkFree();
	}
}
