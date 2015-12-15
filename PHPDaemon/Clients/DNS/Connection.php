<?php
namespace PHPDaemon\Clients\DNS;

use PHPDaemon\Clients\DNS\Pool;
use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Utils\Binary;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;

/**
 * @package    NetworkClients
 * @subpackage DNSClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Connection extends ClientConnection {
	
	/**
	 * @TODO DESCR
	 */
	const STATE_PACKET = 1;

	/**
	 * @var integer Sequence
	 */
	protected $seq = 0;

	/**
	 * @var boolean Keepalive?
	 */
	protected $keepalive = true;

	/**
	 * @var array Response
	 */
	public $response = [];

	/**
	 * @var boolean Current packet size
	 */
	protected $pctSize = 0;

	/**
	 * @var integer Default low mark. Minimum number of bytes in buffer.
	 */
	protected $lowMark = 2;

	/**
	 * @var integer Default high mark. Maximum number of bytes in buffer.
	 */
	protected $highMark = 512;

	/**
	 * Called when new UDP packet received.
	 * @param  string $pct
	 * @return void
	 */
	public function onUdpPacket($pct) {
		$orig           = $pct;
		$this->response = [];
		/*$id = */
		Binary::getWord($pct);
		$bitmap = Binary::getBitmap(Binary::getByte($pct)) . Binary::getBitmap(Binary::getByte($pct));
		//$qr = (int) $bitmap[0];
		$opcode = bindec(substr($bitmap, 1, 4));
		//$aa = (int) $bitmap[5];
		//$tc = (int) $bitmap[6];
		//$rd = (int) $bitmap[7];
		//$ra = (int) $bitmap[8];
		//$z = bindec(substr($bitmap, 9, 3));
		//$rcode = bindec(substr($bitmap, 12));
		$qdcount = Binary::getWord($pct);
		$ancount = Binary::getWord($pct);
		$nscount = Binary::getWord($pct);
		$arcount = Binary::getWord($pct);
		for ($i = 0; $i < $qdcount; ++$i) {
			$name     = Binary::parseLabels($pct, $orig);
			$typeInt  = Binary::getWord($pct);
			$type     = isset(Pool::$type[$typeInt]) ? Pool::$type[$typeInt] : 'UNK(' . $typeInt . ')';
			$classInt = Binary::getWord($pct);
			$class    = isset(Pool::$class[$classInt]) ? Pool::$class[$classInt] : 'UNK(' . $classInt . ')';
			if (!isset($this->response[$type])) {
				$this->response[$type] = [];
			}
			$record                    = [
				'name'  => $name,
				'type'  => $type,
				'class' => $class,
			];
			$this->response['query'][] = $record;
		}
		$getResRecord = function (&$pct) use ($orig) {
			$name     = Binary::parseLabels($pct, $orig);
			$typeInt  = Binary::getWord($pct);
			$type     = isset(Pool::$type[$typeInt]) ? Pool::$type[$typeInt] : 'UNK(' . $typeInt . ')';
			$classInt = Binary::getWord($pct);
			$class    = isset(Pool::$class[$classInt]) ? Pool::$class[$classInt] : 'UNK(' . $classInt . ')';
			$ttl      = Binary::getDWord($pct);
			$length   = Binary::getWord($pct);
			$data     = binarySubstr($pct, 0, $length);
			$pct      = binarySubstr($pct, $length);

			$record = [
				'name'  => $name,
				'type'  => $type,
				'class' => $class,
				'ttl'   => $ttl,
			];

			if ($type === 'A') {
				if ($data === "\x00") {
					$record['ip']  = false;
					$record['ttl'] = 5;
				}
				else {
					$record['ip'] = inet_ntop($data);
				}
			}
			elseif ($type === 'NS') {
				$record['ns'] = Binary::parseLabels($data);
			}
			elseif ($type === 'CNAME') {
				$record['cname'] = Binary::parseLabels($data, $orig);
			}

			return $record;
		};
		for ($i = 0; $i < $ancount; ++$i) {
			$record = $getResRecord($pct);
			if (!isset($this->response[$record['type']])) {
				$this->response[$record['type']] = [];
			}
			$this->response[$record['type']][] = $record;
		}
		for ($i = 0; $i < $nscount; ++$i) {
			$record = $getResRecord($pct);
			if (!isset($this->response[$record['type']])) {
				$this->response[$record['type']] = [];
			}
			$this->response[$record['type']][] = $record;
		}
		for ($i = 0; $i < $arcount; ++$i) {
			$record = $getResRecord($pct);
			if (!isset($this->response[$record['type']])) {
				$this->response[$record['type']] = [];
			}
			$this->response[$record['type']][] = $record;
		}
		$this->onResponse->executeOne($this->response);
		if (!$this->keepalive) {
			$this->finish();
			return;
		}
		else {
			$this->checkFree();
		}
	}

	/**
	 * Called when new data received
	 * @return void
	 */
	public function onRead() {
		start:
		if ($this->type === 'udp') {
			$this->onUdpPacket($this->read($this->getInputLength()));
		}
		if ($this->state === self::STATE_ROOT) {
			if (false === ($hdr = $this->readExact(2))) {
				return; // not enough data
			}
			$this->pctSize = Binary::bytes2int($hdr, true);
			$this->setWatermark($this->pctSize);
			$this->state = self::STATE_PACKET;
		}
		if ($this->state === self::STATE_PACKET) {
			if (false === ($pct = $this->readExact($this->pctSize))) {
				return; // not enough data
			}
			$this->state = self::STATE_ROOT;
			$this->setWatermark(2);
			$this->onUdpPacket($pct);
		}
		goto start;
	}

	/**
	 * Gets the host information
	 * @param  string   $hostname Hostname
	 * @param  callable $cb       Callback
	 * @callback $cb ( )
	 * @return void
	 */
	public function get($hostname, $cb) {
		$this->onResponse->push($cb);
		$this->setFree(false);
		$e         = explode(':', $hostname, 3);
		$hostname  = $e[0];
		$qtype     = isset($e[1]) ? $e[1] : 'A';
		$qclass    = isset($e[2]) ? $e[2] : 'IN';
		$QD        = [];
		$qtypeInt  = array_search($qtype, Pool::$type, true);
		$qclassInt = array_search($qclass, Pool::$class, true);
		if (($qtypeInt === false) || ($qclassInt === false)) {
			call_user_func($cb, false);
			return;
		}
		$q      = Binary::labels($hostname) . // domain
				Binary::word($qtypeInt) .
				Binary::word($qclassInt);
		$QD[]   = $q;
		$packet =
				Binary::word(++$this->seq) . // Query ID
				Binary::bitmap2bytes(
					'0' . // QR = 0
					'0000' . // OPCODE = 0000 (standard query)
					'0' . // AA = 0
					'0' . // TC = 0
					'1' . // RD = 1

					'0' . // RA = 0, 
					'000' . // reserved
					'0000' // RCODE
					, 2) .
				Binary::word(sizeof($QD)) . // QDCOUNT
				Binary::word(0) . // ANCOUNT
				Binary::word(0) . // NSCOUNT
				Binary::word(0) . // ARCOUNT
				implode('', $QD);
		if ($this->type === 'udp') {
			$this->write($packet);
		}
		else {
			$this->write(Binary::word(strlen($packet)) . $packet);
		}
	}

	/**
	 * Called when connection finishes
	 * @return void
	 */
	public function onFinish() {
		$this->onResponse->executeAll(false);
		parent::onFinish();
	}
}
