<?php
namespace PHPDaemon\Clients\WebSocket;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Utils\Binary;
use PHPDaemon\Utils\Crypt;

/**
 * Class Connection
 * @package Clients
 * @subpackage WebSocket
 * @author Kozin Denis <kozin.alizarin.denis@gmail.com>
 * @author Vasily Zorin <maintainer@daemon.io>
 */
class Connection extends ClientConnection {

	/**
	 * Globally Unique Identifier
	 * @see http://tools.ietf.org/html/rfc6455
	 */
	const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

	const STATE_HEADER                = 1;
	const STATE_DATA                  = 2;

	/**
	 * @var string
	 */
	protected $key;

	/**
	 * @var array
	 */
	public $headers = [];

	/**
	 * @var int
	 */
	protected $state = self::STATE_STANDBY;

	/**
	 * @var array
	 */
	protected $opCodes = [
		1   => Pool::TYPE_TEXT,
		2   => Pool::TYPE_BINARY,
		8   => Pool::TYPE_CLOSE,
		9   => Pool::TYPE_PING,
		10  => Pool::TYPE_PONG
	];

	/**
	 * @var string
	 */
	public $type;

	/**
	 * @var int
	 */
	protected $pctLength = 0;

	/**
	 * @var string
	 */
	protected $isMasked = false;

	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		$this->setWatermark(2, $this->pool->maxAllowedPacket);
		Crypt::randomString(16, null, function($string) {
			$this->key = base64_encode($string);
			$this->write('GET /'.$this->path." HTTP/1.1\r\nHost: ".$this->host.($this->port != 80 ? ':' . $this->port : '')."\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ".$this->key."\r\nSec-WebSocket-Version: 13\r\n\r\n");
		});
	}

	/**
	 * Called when new data received
	 * @return void
	 */
	public function onRead() {
		start:
		if ($this->state === static::STATE_HEADER) {
			$l = $this->getInputLength();
			if ($l < 2) {
				return;
			}
			$hdr = $this->look(2);
			$fb = Binary::getbitmap(ord($hdr));
			$fin = (bool) $fb{0};
			$opCode = bindec(substr($fb, 4, 4));

			if (isset($this->opCodes[$opCode])) {
				$this->type = $this->opCodes[$opCode];
			}
			else {
				$this->log('opCode: '. $opCode . ': unknown frame type');
				$this->finish();
				return;
			}
			$sb = ord(binarySubstr($hdr, 1));
			$sbm = Binary::getbitmap($sb);
			$this->isMasked = (bool) $sbm{0};
			$payloadLength = $sb & 127;

			if ($payloadLength <= 125) {
				$this->drain(2);
				$this->pctLength = $payloadLength;
			}
			elseif ($payloadLength === 126) {
				if ($l < 4) {
					return;
				}
				$this->drain(2);
				$this->pctLength = Binary::b2i($this->read(2));

			}
			elseif ($payloadLength === 127) {
				if ($l < 10) {
					return;
				}
				$this->drain(2);
				$this->pctLength = Binary::b2i($this->read(8));
			}

			if ($this->pool->maxAllowedPacket < $this->pctLength) {
				Daemon::$process->log('max-allowed-packet ('.$this->pool->config->maxallowedpacket->getHumanValue().') exceed, aborting connection');
				$this->finish();
				return;
			}
			$this->setWatermark($this->pctLength + ($this->isMasked ? 4 : 0));
			$this->state = static::STATE_DATA;
		}

		if ($this->state === static::STATE_DATA) {
			if ($this->getInputLength() < $this->pctLength + ($this->isMasked ? 4 : 0)) {
				return;
			}
			$this->state = static::STATE_HEADER;
			$this->setWatermark(2);
			if ($this->isMasked) {
				$this->trigger('frame', static::mask($this->read(4), $this->read($this->pctLength)));
			} else {
				$this->trigger('frame', $this->read($this->pctLength));
			}
		}
		if ($this->state === static::STATE_STANDBY) {
			while (($line = $this->readLine()) !== null) {
				$line = trim($line);
				if ($line === '') {
					$expectedKey = base64_encode(pack('H*', sha1($this->key . static::GUID)));
					if (isset($this->headers['HTTP_SEC_WEBSOCKET_ACCEPT']) && $expectedKey === $this->headers['HTTP_SEC_WEBSOCKET_ACCEPT']) {
						$this->state = static::STATE_HEADER;
						if ($this->onConnected) {
							$this->connected = true;
							$this->onConnected->executeAll($this);
							$this->onConnected = null;
						}
						$this->trigger('connected');
						goto start;
					}
					else {
						Daemon::$process->log(__METHOD__ . ': Handshake failed. Connection to ' . $this->url . ' failed.');
						$this->finish();
					}
				}
				else {
					$e = explode(': ', $line);
					if (isset($e[1])) {
						$this->headers['HTTP_' . strtoupper(strtr($e[0], ['-' => '_']))] = $e[1];
					}
				}
			}
			return;
		}
		goto start;
	}

	/**
	 * Send frame to WebSocket server
	 * @param string  $payload
	 * @param string  $type
	 * @param boolean $isMasked
	 */
	public function sendFrame($payload, $type = Pool::TYPE_TEXT, $isMasked = true) {
		$payloadLength = strlen($payload);
		if ($payloadLength > $this->pool->maxAllowedPacket) {
			Daemon::$process->log('max-allowed-packet ('.$this->pool->config->maxallowedpacket->getHumanValue().') exceed, aborting connection');
			return;
		}

		$firstByte = '';
		switch($type) {
			case Pool::TYPE_TEXT:
				$firstByte = 129;
				break;
			case Pool::TYPE_CLOSE:
				$firstByte = 136;
				break;
			case Pool::TYPE_PING:
				$firstByte = 137;
				break;
			case Pool::TYPE_PONG:
				$firstByte = 138;
				break;
		}

		$hdrPacket = chr($firstByte);

		$isMaskedInt = $isMasked ? 128 : 0;
		if ($payloadLength <= 125) {
			$hdrPacket .= chr($payloadLength + $isMaskedInt);
		}
		elseif ($payloadLength <= 65535) {
			$hdrPacket .= chr(126 + $isMaskedInt) . // 126 + 128
				chr($payloadLength >> 8) .
				chr($payloadLength & 0xFF);
		}
		else {
			$hdrPacket .= chr(127 + $isMaskedInt) . // 127 + 128
				chr($payloadLength >> 56) .
				chr($payloadLength >> 48) .
				chr($payloadLength >> 40) .
				chr($payloadLength >> 32) .
				chr($payloadLength >> 24) .
				chr($payloadLength >> 16) .
				chr($payloadLength >> 8) .
				chr($payloadLength & 0xFF);
		}
		$this->write($hdrPacket);
		if ($isMasked) {
			$this->write($mask = chr(mt_rand(0, 0xFF)) .
				chr(mt_rand(0, 0xFF)) .
				chr(mt_rand(0, 0xFF)) .
				chr(mt_rand(0, 0xFF)));
			$this->write(static::mask($mask, $payload));
		} else {
			$this->write($payload);
		}
	}

	/**
	 * @TODO
	 * @return void
	 */
	public function onFinish() {
		parent::onFinish();
		$this->trigger('disconnected');
	}

	/**
	 * @TODO
	 * @param  string $mask
	 * @param  string $str
	 * @return string
	 */
	protected static function mask($mask, $str) {
		$out = '';
		$l = strlen($str);
		$ml = strlen($mask);
		while (($o = strlen($out)) < $l) {
			$out .= binarySubstr($str, $o, $ml) ^ $mask;
		}
		return $out;
	}
}
