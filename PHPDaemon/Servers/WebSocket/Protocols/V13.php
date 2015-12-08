<?php
namespace PHPDaemon\Servers\WebSocket\Protocols;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Servers\WebSocket\Connection;
use PHPDaemon\Utils\Binary;

/**
 * Websocket protocol 13
 * @see    http://datatracker.ietf.org/doc/rfc6455/?include_text=1
 */

class V13 extends Connection {
	const CONTINUATION = 0;
	const STRING       = 0x1;
	const BINARY       = 0x2;
	const CONNCLOSE    = 0x8;
	const PING         = 0x9;
	const PONG         = 0xA;
	protected static $opcodes = [
		0   => 'CONTINUATION',
		0x1 => 'STRING',
		0x2 => 'BINARY',
		0x8 => 'CONNCLOSE',
		0x9 => 'PING',
		0xA => 'PONG',
	];
	protected $outgoingCompression = 0;

	protected $framebuf = '';

	/**
	 * Sends a handshake message reply
	 * @param string Received data (no use in this class)
	 * @return boolean OK?
	 */
	public function sendHandshakeReply($extraHeaders = '') {
		if (!isset($this->server['HTTP_SEC_WEBSOCKET_KEY']) || !isset($this->server['HTTP_SEC_WEBSOCKET_VERSION'])) {
			return false;
		}
		if ($this->server['HTTP_SEC_WEBSOCKET_VERSION'] !== '13' && $this->server['HTTP_SEC_WEBSOCKET_VERSION'] !== '8') {
			return false;
		}

		if (isset($this->server['HTTP_ORIGIN'])) {
			$this->server['HTTP_SEC_WEBSOCKET_ORIGIN'] = $this->server['HTTP_ORIGIN'];
		}
		if (!isset($this->server['HTTP_SEC_WEBSOCKET_ORIGIN'])) {
			$this->server['HTTP_SEC_WEBSOCKET_ORIGIN'] = '';
		}
		$this->write("HTTP/1.1 101 Switching Protocols\r\n"
				. "Upgrade: WebSocket\r\n"
				. "Connection: Upgrade\r\n"
				. "Date: " . date('r') . "\r\n"
				. "Sec-WebSocket-Origin: " . $this->server['HTTP_SEC_WEBSOCKET_ORIGIN'] . "\r\n"
				. "Sec-WebSocket-Location: ws://" . $this->server['HTTP_HOST'] . $this->server['REQUEST_URI'] . "\r\n"
				. "Sec-WebSocket-Accept: " . base64_encode(sha1(trim($this->server['HTTP_SEC_WEBSOCKET_KEY']) . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true)) . "\r\n"
		);
		if (isset($this->server['HTTP_SEC_WEBSOCKET_PROTOCOL'])) {
			$this->writeln("Sec-WebSocket-Protocol: " . $this->server['HTTP_SEC_WEBSOCKET_PROTOCOL']);
		}

		if ($this->pool->config->expose->value) {
			$this->writeln('X-Powered-By: phpDaemon/' . Daemon::$version);
		}

		$this->writeln($extraHeaders);

		return true;
	}


	/**
	 * Sends a frame.
	 * @param  string   $data  Frame's data.
	 * @param  string   $type  Frame's type. ("STRING" OR "BINARY")
	 * @param  callable $cb    Optional. Callback called when the frame is received by client.
	 * @callback $cb ( )
	 * @return boolean         Success.
	 */
	public function sendFrame($data, $type = null, $cb = null) {
		if (!$this->handshaked) {
			return false;
		}

		if ($this->finished && $type !== 'CONNCLOSE') {
			return false;
		}

		/*if (in_array($type, ['STRING', 'BINARY']) && ($this->outgoingCompression > 0) && in_array('deflate-frame', $this->extensions)) {
			//$data = gzcompress($data, $this->outgoingCompression);
			//$rsv1 = 1;
		}*/

		$fin = 1;
		$rsv1 = 0;
		$rsv2 = 0;
		$rsv3 = 0;
		$this->write(chr(bindec($fin . $rsv1 . $rsv2 . $rsv3 . str_pad(decbin($this->getFrameType($type)), 4, '0', STR_PAD_LEFT))));
		$dataLength  = strlen($data);
		$isMasked    = false;
		$isMaskedInt = $isMasked ? 128 : 0;
		if ($dataLength <= 125) {
			$this->write(chr($dataLength + $isMaskedInt));
		}
		elseif ($dataLength <= 65535) {
			$this->write(chr(126 + $isMaskedInt) . // 126 + 128
					chr($dataLength >> 8) .
					chr($dataLength & 0xFF));
		}
		else {
			$this->write(chr(127 + $isMaskedInt) . // 127 + 128
					chr($dataLength >> 56) .
					chr($dataLength >> 48) .
					chr($dataLength >> 40) .
					chr($dataLength >> 32) .
					chr($dataLength >> 24) .
					chr($dataLength >> 16) .
					chr($dataLength >> 8) .
					chr($dataLength & 0xFF));
		}
		if ($isMasked) {
			$mask	= chr(mt_rand(0, 0xFF)) .
					chr(mt_rand(0, 0xFF)) .
					chr(mt_rand(0, 0xFF)) .
					chr(mt_rand(0, 0xFF));
			$this->write($mask . $this->mask($data, $mask));
		}
		else {
			$this->write($data);
		}
		if ($cb !== null) {
			$this->onWriteOnce($cb);
		}
		return true;
	}

	/**
	 * Apply mask
	 * @param $data
	 * @param string|false $mask
	 * @return mixed
	 */
	public function mask($data, $mask) {
		for ($i = 0, $l = strlen($data), $ml = strlen($mask); $i < $l; $i++) {
			$data[$i] = $data[$i] ^ $mask[$i % $ml];
		}
		return $data;
	}

	/**
	 * Called when new data received
	 * @see http://tools.ietf.org/html/draft-ietf-hybi-thewebsocketprotocol-10#page-16
	 * @return void
	 */
	public function onRead() {
		if ($this->state === self::STATE_PREHANDSHAKE) {
			if (!$this->handshake()) {
				return;
			}
		}
		if ($this->state === self::STATE_HANDSHAKED) {

			while (($buflen = $this->getInputLength()) >= 2) {
				$first = ord($this->look(1)); // first byte integer (fin, opcode)
				$firstBits = decbin($first);
				$opcode = (int)bindec(substr($firstBits, 4, 4));
				if ($opcode === 0x8) { // CLOSE
					$this->finish();
					return;
				}
				$opcodeName = isset(static::$opcodes[$opcode]) ? static::$opcodes[$opcode] : false;
				if (!$opcodeName) {
					Daemon::log(get_class($this) . ': Undefined opcode ' . $opcode);
					$this->finish();
					return;
				}
				$second = ord($this->look(1, 1)); // second byte integer (masked, payload length)
				$fin = (bool)($first >> 7);
				$isMasked = (bool)($second >> 7);
				$dataLength = $second & 0x7f;
				$p = 2;
				if ($dataLength === 0x7e) { // 2 bytes-length
					if ($buflen < $p + 2) {
						return; // not enough data yet
					}
					$dataLength = Binary::bytes2int($this->look(2, $p), false);
					$p += 2;
				} elseif ($dataLength === 0x7f) { // 8 bytes-length
					if ($buflen < $p + 8) {
						return; // not enough data yet
					}
					$dataLength = Binary::bytes2int($this->look(8, $p));
					$p += 8;
				}
				if ($this->pool->maxAllowedPacket <= $dataLength) {
					// Too big packet
					$this>finish();
					return;
				}
				if ($isMasked) {
					if ($buflen < $p + 4) {
						return; // not enough data yet
					}
					$mask = $this->look(4, $p);
					$p += 4;
				}
				if ($buflen < $p + $dataLength) {
					return; // not enough data yet
				}
				$this->drain($p);
				$data = $this->read($dataLength);
				if ($isMasked) {
					$data = $this->mask($data, $mask);
				}
				//Daemon::log(Debug::dump(array('ext' => $this->extensions, 'rsv1' => $firstBits[1], 'data' => Debug::exportBytes($data))));
				/*if ($firstBits[1] && in_array('deflate-frame', $this->extensions)) { // deflate frame
					$data = gzuncompress($data, $this->pool->maxAllowedPacket);
				}*/
				if (!$fin) {
					$this->framebuf .= $data;
				} else {
					$this->onFrame($this->framebuf . $data, $opcodeName);
					$this->framebuf = '';
				}
			}
		}
	}
}
