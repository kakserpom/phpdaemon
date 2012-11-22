<?php

/**
 * @package NetworkServers
 * @subpackage SocksServer
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class SocksServer extends NetworkServer {
	
	/**
	 * Setting default config options
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'listen'         => 'tcp://0.0.0.0',
			// default port
			'port'     => 1080,
			// authentication required
			'auth'           => 0,
			// user name
			'username'       => 'User',
			// password
			'password'       => 'Password',
			// allowed clients ip list
			'allowedclients' => null,
		);
	}
}

class SocksServerConnection extends Connection {
	public $ver; // protocol version (X'04' / X'05')
	public $state = 0; // (0 - start, 1 - aborted, 2 - handshaked, 3 - authorized, 4 - data exchange)
	public $slave;
	const STATE_ABORTED = 1;
	const STATE_HANDSHAKED = 2;
	const STATE_AUTHORIZED = 3;
	const STATE_DATAFLOW = 4;

	/**
	 * Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		if ($this->state === self::STATE_DATAFLOW) {
			// Data exchange
			if ($this->slave) {
				$this->slave->write($buf);
			}

			return;
		}
		
		$this->buf .= $buf;

		start:

		$l = strlen($this->buf);
	
		if ($this->state === self::STATE_ROOT) {
			// Start
			if ($l < 2) {
				// Not enough data yet
				return;
			} 
			
			$n = ord(binarySubstr($this->buf, 1, 1));

			if ($l < $n + 2) {
				// Not enough data yet
				return;
			} 
			
			$this->ver = binarySubstr($this->buf, 0, 1);
			$methods = binarySubstr($this->buf, 2, $n);
			$this->buf = binarySubstr($this->buf, $n + 2);

			if (!$this->pool->config->auth->value) {
				// No auth
				$m = "\x00";
				$this->state = self::STATE_AUTHORIZED;
			} 
			elseif (strpos($methods, "\x02") !== FALSE) {
				// Username/Password authentication
				$m = "\x02";
				$this->state = self::STATE_HANDSHAKED;
			} else {
				// No allowed methods
				$m = "\xFF";
				$this->state = self::STATE_ABORTED;
			}

			$this->write($this->ver . $m);

			if ($this->state === self::STATE_ABORTED) {
				$this->finish();
			} else {
				goto start;
			}
		}
		elseif ($this->state === self::STATE_HANDSHAKED) {
			// Handshaked
			if ($l < 3) {
				// Not enough data yet
				return;
			} 

			$ver = binarySubstr($this->buf, 0, 1);

			if ($ver !== $this->ver) {
				$this->finish();
				return;
			}
	
			$ulen = ord(binarySubstr($this->buf, 1, 1));

			if ($l < 3 + $ulen) {
				// Not enough data yet
				return;
			} 

			$username = binarySubstr($this->buf, 2, $ulen);
			$plen = ord(binarySubstr($this->buf, 1, 1));

			if ($l < 3 + $ulen + $plen) {
				// Not enough data yet
				return;
			} 

			$password = binarySubstr($this->buf, 2 + $ulen, $plen);

			if (
				($username != $this->pool->config->username->value) 
				|| ($password != $this->pool->config->password->value)
			) {
				$this->state = 1;
				$m = "\x01";
			} else {
				$this->state = 3;
				$m = "\x00";
			}
			
			$this->buf = binarySubstr($this->buf, 3 + $ulen + $plen);
			$this->write($this->ver . $m);

			if ($this->state === self::STATE_ABORTED) {
				$this->finish();
			} else {
				goto start;
			}
		} 
		elseif ($this->state === self::STATE_AUTHORIZED) {
			// Ready for query
			if ($l < 4) {
				// Not enough data yet
				return;
			}

			$ver = binarySubstr($this->buf, 0, 1);

			if ($ver !== $this->ver) {
				$this->finish();
				return;
			}
			
			$cmd = binarySubstr($this->buf, 1, 1);
			$atype = binarySubstr($this->buf, 3, 1);
			$pl = 4;

			if ($atype === "\x01") {
				$address = inet_ntop(binarySubstr($this->buf, $pl, 4)); 
				$pl += 4;
			}
			elseif ($atype === "\x03") {
				$len = ord(binarySubstr($this->buf, $pl, 1));
				++$pl;
				$address = binarySubstr($this->buf, $pl, $len);
				$pl += $len;
			}
			elseif ($atype === "\x04") {
				$address = inet_ntop(binarySubstr($this->buf, $pl, 16)); 
				$pl += 16;
			} else {
				$this->finish();
				return;
			}
			
			$u = unpack('nport', $bin = binarySubstr($this->buf, $pl, 2));
			$port = $u['port'];
			$pl += 2;
			$this->buf = binarySubstr($this->buf, $pl);

			$conn = $this->pool->connectTo($this->destAddr = $address, $this->destPort = $port, 'SocksServerSlaveConnection');

			if (!$conn) {
				// Early connection error
				$this->write($this->ver . "\x05");
				$this->finish();
			} else {
				$this->slave = $conn;
				$this->slave->client = $this;
				$this->slave->write($this->buf);
				$this->buf = '';
				$this->state = self::STATE_DATAFLOW;
			}
		}
	}

	public function onSlaveReady($code) {
		$reply =
			$this->ver // Version
			. chr($code) // Status
			. "\x00"; // Reserved

		if (
			Daemon::$useSockets 
			&& socket_getsockname($this->fd, $address, $port)
		) {
			$reply .=
				(strpos($address, ':') === FALSE ? "\x01" : "\x04") // IPv4/IPv6
				. inet_pton($address) // Address
				. "\x00\x00"; //pack('n',$port) // Port
		} else {
			$reply .=
				"\x01"
				. "\x00\x00\x00\x00"
				. "\x00\x00";
		}

		$this->write($reply);
	}

	
	public function onFinish() {
		if (isset($this->slave)) {
			$this->slave->finish();
			unset($this->slave);
		}
	}
}
class SocksServerSlave extends NetworkServer {}
class SocksServerSlaveConnection extends Connection {

	public $client;
	public $ready = false;

	/**
	 * Called when the connection is ready to accept new data.
	 * @return void
	 */
	public function onWrite() {
		if (!$this->ready) {
			$this->ready = TRUE;
		
			if (isset($this->client)) {
				$this->client->onSlaveReady(0x00);
			}
		}
	}
	
	/**
	 * Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		$this->client->write($buf);
	}

	/**
	 * Event of Connection
	 * @return void
	 */
	public function onFinish() {
		if (isset($this->client)) {
			$this->client->finish();
		}
	
		unset($this->client);
	}
}
