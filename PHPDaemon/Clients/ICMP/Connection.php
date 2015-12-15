<?php
namespace PHPDaemon\Clients\ICMP;

use PHPDaemon\Core\CallbackWrapper;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Utils\Binary;

/**
 * @package    Applications
 * @subpackage ICMPClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Connection extends ClientConnection {
	/**
	 * @var integer Packet sequence
	 */
	protected $seq = 0;

	/**
	 * @var array @TODO DESCR
	 */
	protected static $unreachableCodes = [
		0x0 => 'netUnreachable',
		0x1 => 'hostUnreachable',
		0x2 => 'protocolUnreachable',
		0x3 => 'portUnreachable',
		0x4 => 'fragmentationNeeded',
		0x5 => 'sourceRouteFailed',
	];

	/**
	 * @var boolean Enable bevConnect?
	 */
	protected $bevConnectEnabled = false;

	/**
	 * Send echo-request
	 * @param callable $cb   Callback
	 * @param string   $data Data
	 * @callback $cb ( )
	 * @return void
	 */
	public function sendEcho($cb, $data = 'phpdaemon') {
		++$this->seq;
		if (strlen($data) % 2 !== 0) {
			$data .= "\x00";
		}
		$packet = pack('ccnnn',
					   8, // type (c)
					   0, // code (c)
					   0, // checksum (n)
					   Daemon::$process->getPid(), // pid (n)
					   $this->seq // seq (n)
				) . $data;
		$packet = substr_replace($packet, self::checksum($packet), 2, 2);
		$this->write($packet);
		$this->onResponse->push([$cb, microtime(true)]);
	}

	/**
	 * Build checksum
	 * @param  string $data Source
	 * @return string       Checksum
	 */
	protected static function checksum($data) {
		$bit = unpack('n*', $data);
		$sum = array_sum($bit);
		if (strlen($data) % 2) {
			$temp = unpack('C*', $data[strlen($data) - 1]);
			$sum += $temp[1];
		}
		$sum = ($sum >> 16) + ($sum & 0xffff);
		$sum += ($sum >> 16);
		return pack('n*', ~$sum);
	}

	/**
	 * Called when new data received
	 * @return void
	 */
	public function onRead() {
		$packet   = $this->read(1024);
		$orig     = $packet;
		$type     = Binary::getByte($packet);
		$code     = Binary::getByte($packet);
		$checksum = Binary::getStrWord($packet);
		$id       = Binary::getWord($packet);
		$seq      = Binary::getWord($packet);
		if ($checksum !== self::checksum(substr_replace($orig, "\x00\x00", 2, 2))) {
			$status = 'badChecksum';
		}
		elseif ($type === 0x03) {
			$status = isset(static::$unreachableCodes[$code]) ? static::$unreachableCodes[$code] : 'unk' . $code . 'unreachable';
		}
		else {
			$status = 'unknownType0x' . dechex($type);
		}
		while (!$this->onResponse->isEmpty()) {
			$el = $this->onResponse->shift();
			if ($el instanceof CallbackWrapper) {
				$el = $el->unwrap();
			}
			list ($cb, $st) = $el;
			call_user_func($cb, microtime(true) - $st, $status);
		}
		$this->finish();
	}
}
