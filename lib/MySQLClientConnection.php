<?php
class MySQLClientConnection extends NetworkClientConnection {

	public $url;                        // Connection's URL.
	public $seq           = 0;          // Pointer of packet sequence.
	public $clientFlags   = 239237;     // Flags of this MySQL client.
	protected $maxPacketSize = 0x1000000;  // Maximum packet size.
	public $charsetNumber = 0x21;       // Charset number.
	public $path        = ''; 	        // Default database name.
	public $user          = 'root';     // Username
	public $password      = '';         // Password
	protected $state     = 0;          // Connection's state. 0 - start, 1 - got initial packet, 2 - auth. packet sent, 3 - auth. error, 4 - handshaked OK
	protected $pstate	= 0;
	const PSTATE_STANDBY = 0;
	const PSTATE_BODY = 1;
	const STATE_GOT_INIT = 1;
	const STATE_AUTH_SENT = 2;
	const STATE_AUTH_ERR = 3;
	const STATE_HANDSHAKED = 4;
	protected $instate       = 0;          // State of pointer of incoming data. 0 - Result Set Header Packet, 1 - Field Packet, 2 - Row Packet
	const INSTATE_HEADER = 0;
	const INSTATE_FIELD = 1;
	const INSTATE_ROW = 2;
	public $pctSize = 0;		 // Packet size
	public $resultRows    = [];    // Resulting rows
	public $resultFields  = [];    // Resulting fields
	public $context;                    // Property holds a reference to user's object
	public $insertId;                   // INSERT_ID()
	public $affectedRows;               // Affected rows number
	public $protover = 0;
	public $timeout = 120;
	public $errno = 0;
	public $errmsg = '';
	protected $lowMark = 4;
	
	/**
	 * Executes the given callback when/if the connection is handshaked
	 * Callback
	 * @return void
	 */
	public function onConnected($cb) {
		if ($this->state == self::STATE_AUTH_ERR) {
			call_user_func($cb, $this, false);
		}
		elseif ($this->state === self::STATE_HANDSHAKED) {
			call_user_func($cb, $this, true);
		}
		else {
			if (!$this->onConnected) {
				$this->onConnected = new StackCallbacks;
			}
			$this->onConnected->push($cb);
		}
	}
	
	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
	}
	
	/**
	 * Converts binary string to integer
	 * @param string Binary string
	 * @param boolean Optional. Little endian. Default value - true.
	 * @return integer Resulting integer
	 */
	public function bytes2int($str, $l = TRUE)
	{
		if ($l) {
			$str = strrev($str);
		}
		
		$dec = 0;
		$len = strlen($str);
		
		for($i = 0; $i < $len; ++$i) {
			$dec += ord(binarySubstr($str, $i, 1)) * pow(0x100, $len - $i - 1);
		}
		
		return $dec;
	}

	/**
	 * Converts integer to binary string
	 * @param integer Length
	 * @param integer Integer
	 * @param boolean Optional. Little endian. Default value - true.
	 * @return string Resulting binary string
	 */
	function int2bytes($len, $int = 0, $l = TRUE) {
		$hexstr = dechex($int);

		if ($len === NULL) {
			if (strlen($hexstr) % 2) {
				$hexstr = "0".$hexstr;
			}
		} else {
			$hexstr = str_repeat('0', $len * 2 - strlen($hexstr)) . $hexstr;
		}
		
		$bytes = strlen($hexstr) / 2;
		$bin = '';
		
		for($i = 0; $i < $bytes; ++$i) {
			$bin .= chr(hexdec(substr($hexstr, $i * 2, 2)));
		}
		
		return $l ? strrev($bin) : $bin;
	}

	/**
	 * Sends a packet
	 * @param string Data
	 * @return boolean Success
	 */
	public function sendPacket($packet) { 
		//Daemon::log('Client --> Server: ' . Debug::exportBytes($packet) . "\n\n");
		return $this->write($this->int2bytes(3, strlen($packet)) . chr($this->seq++) . $packet);;
	}

	/**
	 * Builds length-encoded binary string
	 * @param string String
	 * @return string Resulting binary string
	 */
	public function buildLenEncodedBinary($s) {
		if ($s === NULL) {
			return "\251";
		}
		
		$l = strlen($s);
		
		if ($l <= 250) {
			return chr($l) . $s;
		}
		
		if ($l <= 0xFFFF) {
			return "\252" . $this->int2bytes(2, $l) . $s;
		}
		
		if ($l <= 0xFFFFFF) {
			return "\254" . $this->int2bytes(3, $l) . $s;
		}
		
		return $this->int2bytes(8, $l) . $s;
	}

	/**
	 * Parses length-encoded binary
	 * @param string Reference to source string
	 * @return integer Result
	 */
	public function parseEncodedBinary(&$s, &$p) {
		$f = ord(binarySubstr($s, $p, 1));
		++$p;

		if ($f <= 250) {
			return $f;
		}
		
		if ($f === 251) {
			return NULL;
		}
		
		if ($f === 255) {
			return FALSE;
		}
		
		if ($f === 252) {
			$o = $p;
			$p += 2;
			
			return $this->bytes2int(binarySubstr($s, $o, 2));
		}
		
		if ($f === 253) {
			$o = $p;
			$p += 3;
			
			return $this->bytes2int(binarySubstr($s, $o, 3));
		}
		
		$o = $p;
		$p =+ 8;
		
		return $this->bytes2int(binarySubstr($s, $o, 8));
	}

	/**
	 * Parse length-encoded string
	 * @param string Reference to source string
	 * @param integer Reference to pointer
	 * @return integer Result
	 */
	public function parseEncodedString(&$s,&$p) {
		$l = $this->parseEncodedBinary($s, $p);

		if (
			($l === null) 
			|| ($l === false)
		) {
			return $l;
		}

		$o = $p;
		$p += $l;

		return binarySubstr($s, $o, $l);
	}

	/**
	 * Generates auth. token
	 * @param string Scramble string
	 * @param string Password
	 * @return string Result
	 */
	public function getAuthToken($scramble, $password) {
		return sha1($scramble . sha1($hash1 = sha1($password, true), true), true) ^ $hash1;
	}

	/**
	 * Sends auth. packet
	 * @param string Scramble string
	 * @param string Password
	 * @return string Result
	 */
	public function auth() {
		if ($this->state !== self::STATE_GOT_INIT) {
			return;
		}
		
		$this->state = self::STATE_AUTH_SENT;
		$this->onResponse->push(function($conn, $result) {
			if ($conn->onConnected) {
				$conn->connected = true;
				$conn->onConnected->executeAll($conn, $result);
				$conn->onConnected = null;
			}
		});
		
		$this->clientFlags =
			MySQLClient::CLIENT_LONG_PASSWORD | 
			MySQLClient::CLIENT_LONG_FLAG | 
			MySQLClient::CLIENT_LOCAL_FILES | 
			MySQLClient::CLIENT_PROTOCOL_41 | 
			MySQLClient::CLIENT_INTERACTIVE | 
			MySQLClient::CLIENT_TRANSACTIONS | 
			MySQLClient::CLIENT_SECURE_CONNECTION | 
			MySQLClient::CLIENT_MULTI_STATEMENTS | 
			MySQLClient::CLIENT_MULTI_RESULTS;

		$this->sendPacket(
			pack('VVc', $this->clientFlags, $this->maxPacketSize, $this->charsetNumber)
			. "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00"
			. $this->user . "\x00"
			. ($this->password === '' ? "\x00" : $this->buildLenEncodedBinary(
				$this->getAuthToken($this->scramble, $this->password)
			))
			. ($this->path !== '' ? $this->path . "\x00" : '')
		);
	}

	/**
	 * Sends SQL-query
	 * @param string Query
	 * @param callback Optional. Callback called when response received.
	 * @return boolean Success
	 */
	public function query($q, $callback = NULL) {
		return $this->command(MySQLClient::COM_QUERY, $q, $callback);
	}

	/**
	 * Sends echo-request
	 * @param callback Optional. Callback called when response received.
	 * @return boolean Success
	 */
	public function ping($callback = NULL) {
		return $this->command(MySQLClient::COM_PING, '', $callback);
	}

	/**
	 * Sends arbitrary command
	 * @param integer Command's code. See constants above.
	 * @param string Data
	 * @param callback Optional. Callback called when response received.
	 * @return boolean Success
	 * @throws MySQLClientSessionFinished
	 */
	public function command($cmd, $q = '', $callback = NULL) {
		if ($this->finished) {
			throw new MySQLClientConnectionFinished;
		}
		
		if ($this->state !== self::STATE_HANDSHAKED) {
			return false;
		}
		
		$this->onResponse->push($callback);
		$this->seq = 0;
		$this->sendPacket(chr($cmd).$q);
		
		return TRUE;
	}

	/**
	 * Sets default database name
	 * @param string Database name
	 * @return boolean Success
	 */
	public function selectDB($name) {
		$this->path = $name;

		if ($this->state !== self::STATE_GOT_INIT) {
			return $this->query('USE `' . $name . '`');
		}
		
		return TRUE;
	}
	public $counter = 0;
	/**
	 * Called when new data received
	 * @return void
	 */
	public function onRead() {
		start:
		if ($this->pstate === self::PSTATE_STANDBY) {
			if (false === ($hdr = $this->readExact(4))) {
					return; //we do not have a whole packet
			}
			$this->pctSize = $this->bytes2int(binarySubstr($hdr, 0, 3));
			$this->setWatermark($this->pctSize);
			$this->pstate = self::PSTATE_BODY;
			$this->seq = ord(binarySubstr($hdr, 3, 1)) + 1;
		}
		// PSTATE_BODY
		if (false === ($pct = $this->readExact($this->pctSize))) {
				return; //we do not have a whole packet
		}
		$this->pstate = self::PSTATE_STANDBY;
		$this->setWatermark(4);
		$p = 0;
		if ($this->state === self::STATE_ROOT) {
			$this->state = self::STATE_GOT_INIT;
			$p = 0;

			$this->protover = ord(binarySubstr($pct, $p++, 1));
			if ($this->protover === 0xFF) { // error
				$fieldCount = $this->protover;
				$this->protover = 0;
				$this->onResponse->push(function($conn, $result) {
					if ($conn->onConnected) {
						$conn->connected = true;
						$conn->onConnected->executeAll($conn, $result);
						$conn->onConnected = null;
					}
				});
				goto field;
			}
			$this->serverver = '';

			while ($p < $this->pctSize) {
				$c = binarySubstr($pct, $p++, 1);

				if ($c === "\x00") {
					break;
				}
				
				$this->serverver .= $c;
			}
		
			$this->threadId = $this->bytes2int(binarySubstr($pct, $p, 4));
			$p += 4;
	
			$this->scramble = binarySubstr($pct, $p, 8);
			$p += 9;
	
			$this->serverCaps = $this->bytes2int(binarySubstr($pct, $p, 2));
			$p += 2;
	
			$this->serverLang = ord(binarySubstr($pct, $p++, 1));
			$this->serverStatus = $this->bytes2int(binarySubstr($pct, $p, 2));
			$p += 2;
			$p += 13;

			$restScramble = binarySubstr($pct, $p, 12);
			$this->scramble .= $restScramble;
			$p += 13;
	
			$this->auth();
		} else {
			$fieldCount = ord(binarySubstr($pct, $p++, 1));
			field:
			if ($fieldCount === 0xFF) {
				// Error packet
				$u = unpack('v', binarySubstr($pct, $p, 2));
				$p += 2;
				
				$this->errno = $u[1];
				$state = binarySubstr($pct, $p, 6);
				$p =+ 6;

				$this->errmsg = binarySubstr($pct, $p, $this->pctSize - $p);
				$this->onError();
				$this->errno = 0;
				$this->errmsg = '';
			}
			elseif ($fieldCount === 0x00) {
				// OK Packet Empty
				if ($this->state === self::STATE_AUTH_SENT) {
					$this->state = self::STATE_HANDSHAKED;
			
					if ($this->path !== '') {
						$this->query('USE `' . $this->path . '`');
					}
				}
		
				$this->affectedRows = $this->parseEncodedBinary($pct, $p);

				$this->insertId = $this->parseEncodedBinary($pct, $p);

				$u = unpack('v', binarySubstr($pct, $p, 2));
				$p += 2;
				
				$this->serverStatus = $u[1];

				$u = unpack('v',binarySubstr($pct, $p, 2));
				$p += 2;
				
				$this->warnCount = $u[1];

				$this->message = binarySubstr($pct, $p, $this->pctSize - $p);
				$this->onResultDone();
			}
			elseif ($fieldCount === 0xFE) { 
				// EOF Packet		
				if ($this->instate === self::INSTATE_ROW) {
					$this->onResultDone();
				}
				else {
					++$this->instate;
				}
			} else {
				// Data packet
				--$p;
		
				if ($this->instate === self::INSTATE_HEADER) {
					// Result Set Header Packet
					$extra = $this->parseEncodedBinary($pct, $p);
					$this->instate = self::INSTATE_FIELD;
				}
				elseif ($this->instate === self::INSTATE_FIELD) {
					// Field Packet
					$field = [
						'catalog'    => $this->parseEncodedString($pct, $p),
						'db'         => $this->parseEncodedString($pct, $p),
						'table'      => $this->parseEncodedString($pct, $p),
						'org_table'  => $this->parseEncodedString($pct, $p),
						'name'       => $this->parseEncodedString($pct, $p),
						'org_name'   => $this->parseEncodedString($pct, $p)
					];

					++$p; // filler

					$u = unpack('v', binarySubstr($pct, $p, 2));
					$p += 2;

					$field['charset'] = $u[1];
					$u = unpack('V', binarySubstr($pct, $p, 4));
					$p += 4;
					$field['length'] = $u[1];

					$field['type'] = ord(binarySubstr($pct, $p, 1));
					++$p;

					$u = unpack('v', binarySubstr($pct, $p, 2));
					$p += 2;
					$field['flags'] = $u[1];

					$field['decimals'] = ord(binarySubstr($pct, $p, 1));
					++$p;

					$this->resultFields[] = $field;
				}
				elseif ($this->instate === self::INSTATE_ROW) {
					// Row Packet
					$row = [];

					for ($i = 0, $nf = sizeof($this->resultFields); $i < $nf; ++$i) {
						$row[$this->resultFields[$i]['name']] = $this->parseEncodedString($pct, $p);
					}
		
					$this->resultRows[] = $row;
				}
			}
		}

		goto start;
	}

	/**
	 * Called when the whole result received
	 * @return void
	 */
	public function onResultDone() {
		$this->instate = self::INSTATE_HEADER;
		$this->onResponse->executeOne($this, true);
		$this->checkFree();
		$this->resultRows = [];
		$this->resultFields = [];
	}

	/**
	 * Called when error occured
	 * @return void
	 */
	public function onError() {
		$this->instate = self::INSTATE_HEADER;
		$this->onResponse->executeOne($this, false);
		$this->checkFree();
		$this->resultRows = [];
		$this->resultFields = [];

		if (($this->state === self::STATE_AUTH_SENT) || ($this->state == self::STATE_GOT_INIT)) {
			// in case of auth error
			$this->state = self::STATE_AUTH_ERR;
			$this->finish();
		}
	
		Daemon::log(__METHOD__ . ' #' . $this->errno . ': ' . $this->errmsg);
	}
}
class MySQLClientConnectionFinished extends Exception {}
