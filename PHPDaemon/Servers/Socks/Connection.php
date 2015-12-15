<?php
namespace PHPDaemon\Servers\Socks;

/**
 * @package    NetworkServers
 * @subpackage SocksServer
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Connection extends \PHPDaemon\Network\Connection {

	/**
	 * @var string protocol version (X'04' / X'05')
	 */
	protected $ver;

	/**
	 * @var integer State: 0 - start, 1 - aborted, 2 - handshaked, 3 - authorized, 4 - data exchange
	 */
	protected $state = 0;

	protected $slave;

	/**
	 * @TODO DESCR
	 */
	const STATE_ABORTED    = 1;

	/**
	 * @TODO DESCR
	 */
	const STATE_HANDSHAKED = 2;

	/**
	 * @TODO DESCR
	 */
	const STATE_AUTHORIZED = 3;

	/**
	 * @TODO DESCR
	 */
	const STATE_DATAFLOW   = 4;

	protected $lowMark = 2;
	protected $highMark = 32768;

	/**
	 * Called when new data received.
	 * @return void
	 */
	public function onRead() {
		if ($this->state === self::STATE_DATAFLOW) {
			// Data exchange
			if ($this->slave) {
				do {
					$this->slave->writeFromBuffer($this->bev->input, $this->bev->input->length);
				} while ($this->bev->input->length > 0);
			}

			return;
		}

		start:

		$l = $this->bev->input->length;

		if ($this->state === self::STATE_ROOT) {
			// Start
			if ($l < 2) {
				// Not enough data yet
				return;
			}
			$n = ord($this->look(1, 1));
			if ($l < $n + 2) {
				// Not enough data yet
				return;
			}
			$this->ver = $this->look(1);
			$this->drain(2);
			$methods = $this->read($n);

			if (!$this->pool->config->auth->value) {
				// No auth
				$m           = "\x00";
				$this->state = self::STATE_AUTHORIZED;
			}
			elseif (strpos($methods, "\x02") !== false) {
				// Username/Password authentication
				$m           = "\x02";
				$this->state = self::STATE_HANDSHAKED;
			}
			else {
				// No allowed methods
				$m           = "\xFF";
				$this->state = self::STATE_ABORTED;
			}

			$this->write($this->ver . $m);

			if ($this->state === self::STATE_ABORTED) {
				$this->finish();
			}
			else {
				goto start;
			}
		}
		elseif ($this->state === self::STATE_HANDSHAKED) {
			// Handshaked
			if ($l < 3) {
				// Not enough data yet
				return;
			}

			$ver = $this->look(1);

			if ($ver !== $this->ver) {
				$this->finish();
				return;
			}

			$ulen = ord($this->look(1, 1));
			if ($l < 3 + $ulen) {
				// Not enough data yet
				return;
			}
			$username = $this->look(2, $ulen);
			$plen     = ord($this->look(2 + $ulen, 1));

			if ($l < 3 + $ulen + $plen) {
				// Not enough data yet
				return;
			}
			$this->drain(3 + $ulen);
			$password = $this->read($plen);

			if (
					($username !== $this->pool->config->username->value)
					|| ($password !== $this->pool->config->password->value)
			) {
				$this->state = self::STATE_ABORTED;
				$m           = "\x01";
			}
			else {
				$this->state = self::STATE_AUTHORIZED;
				$m           = "\x00";
			}
			$this->write($this->ver . $m);

			if ($this->state === self::STATE_ABORTED) {
				$this->finish();
			}
			else {
				goto start;
			}
		}
		elseif ($this->state === self::STATE_AUTHORIZED) {
			// Ready for query
			if ($l < 4) {
				// Not enough data yet
				return;
			}
			$ver = $this->read(1);

			if ($ver !== $this->ver) {
				$this->finish();
				return;
			}

			$cmd = $this->read(1);
			$this->drain(1);
			$atype = $this->read(1);
			if ($atype === "\x01") {
				$address = inet_ntop($this->read(4));
			}
			elseif ($atype === "\x03") {
				$len     = ord($this->read(1));
				$address = $this->read($len);
			}
			elseif ($atype === "\x04") {
				$address = inet_ntop($this->read(16));
			}
			else {
				$this->finish();
				return;
			}

			$u    = unpack('nport', $this->read(2));
			$port = $u['port'];

			$this->destAddr = $address;
			$this->destPort = $port;
			$this->pool->connect('tcp://' . $this->destAddr . ':' . $this->destPort, function ($conn) {
				if (!$conn) {
					// Early connection error
					$this->write($this->ver . "\x05");
					$this->finish();
				}
				else {
					$conn->setClient($this);
					$this->state = self::STATE_DATAFLOW;
					$conn->getSocketName($addr, $port);
					$this->slave = $conn;
					$this->onSlaveReady(0x00, $addr, $port);
					$this->onReadEv(null);
				}
			}, 'SocksServerSlaveConnection');
		}
	}

	/**
	 * @TODO
	 * @param integer $code
	 * @param string  $addr
	 * @param integer $port
	 */
	public function onSlaveReady($code, $addr, $port) {
		$reply =
				$this->ver // Version
				. chr($code) // Status
				. "\x00"; // Reserved
		if ($addr) {
			$reply .=
					(strpos($addr, ':') === FALSE ? "\x01" : "\x04") // IPv4/IPv6
					. inet_pton($addr) // Address
					. "\x00\x00"; //pack('n',$port) // Port
		}
		else {
			$reply .=
					"\x01"
					. "\x00\x00\x00\x00"
					. "\x00\x00";
		}

		$this->write($reply);
	}

	/**
	 * @TODO DESCR
	 */
	public function onFinish() {
		if (isset($this->slave)) {
			$this->slave->finish();
			unset($this->slave);
		}
	}
}
