<?php
class MySQLClientConnection extends NetworkClientConnection {

	/**
	 * Sequence
	 * @var integer
	 */
	public $seq           = 0;          // Pointer of packet sequence.

	/**
	 * Client flags
	 * @var integer
	 */
	public $clientFlags   = 239237;     // Flags of this MySQL client.

	/**
	 * Maximum packet size
	 * @var integer
	 */
	protected $maxPacketSize = 0x1000000;  // Maximum packet size.

	/**
	 * Charset number (see MySQL charset list)
	 * @var integer
	 */
	public $charsetNumber = 0x21;       // Charset number.

	/**
	 * User name
	 * @var string
	 */
	public $user          = 'root';     // Username

	/**
	 * Password
	 * @var string
	 */
	public $password      = '';         // Password

	/**
	 * Database name
	 * @var string
	 */
	public $dbname      = '';


	const STATE_STANDBY = 0;
	const STATE_BODY = 1;

	/**
	 * Phase
	 * @var string
	 */
	protected $phase     = 0; 
	const PHASE_GOT_INIT = 1;
	const PHASE_AUTH_SENT = 2;
	const PHASE_AUTH_ERR = 3;
	const PHASE_HANDSHAKED = 4;


	/**
	 * State of pointer of incoming data. 0 - Result Set Header Packet, 1 - Field Packet, 2 - Row Packet
	 * @var integer
	 */
	protected $rsState       = 0;
	const RS_STATE_HEADER = 0;
	const RS_STATE_FIELD = 1;
	const RS_STATE_ROW = 2;

	/**
	 * Packet size
	 * @var integer
	 */
	protected $pctSize = 0;

	/**
	 * Result rows
	 * @var array
	 */
	public $resultRows    = [];

	/**
	 * Result fields
	 * @var array
	 */
	public $resultFields  = [];

	/**
	 * Property holds a reference to user's object
	 * @var object
	 */
	public $context;

	/**
	 * INSERT_ID()
	 * @var integer
	 */
	public $insertId;

	/**
	 * Affected rows
	 * @var integer
	 */
	public $affectedRows;

	/**
	 * Protocol version
	 * @var integer
	 */
	public $protover = 0;

	/**
	 * Timeout
	 * @var integer
	 */
	public $timeout = 120;

	/**
	 * Error number
	 * @var integer
	 */
	public $errno = 0;

	/**
	 * Error message
	 * @var integer
	 */
	public $errmsg = '';

	/**
	 * Low mark
	 * @var integer
	 */
	protected $lowMark = 4;
	
	/**
	 * Executes the given callback when/if the connection is handshaked
	 * Callback
	 * @return void
	 */
	public function onConnected($cb) {
		if ($this->phase === self::PHASE_AUTH_ERR) {
			call_user_func($cb, $this, false);
		}
		elseif ($this->phase === self::PHASE_HANDSHAKED) {
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
		if (strlen($this->path) && !strlen($this->dbname)) {
			$this->dbname = $this->path;
		}
	}


	/**
	 * Sends a packet
	 * @param string Data
	 * @return boolean Success
	 */
	public function sendPacket($packet) { 
		//Daemon::log('Client --> Server: ' . Debug::exportBytes($packet) . "\n\n");
		return $this->write(Binary::int2bytes(3, strlen($packet), true) . chr($this->seq++) . $packet);
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
			return "\252" . Binary::int2bytes(2, true) . $s;
		}
		
		if ($l <= 0xFFFFFF) {
			return "\254" . Binary::int2bytes(3, true) . $s;
		}
		
		return Binary::int2bytes(8, $l, true) . $s;
	}

	/**
	 * Parses length-encoded binary integer
	 * @return integer Result
	 */
	public function parseEncodedBinary() {
		$f = ord($this->read(1));
		if ($f <= 250) {
			return $f;
		}
		if ($f === 251) {
			return null;
		}
		if ($f === 255) {
			return false;
		}
		if ($f === 252) {			
			return Binary::bytes2int($this->read(2), true);
		}
		if ($f === 253) {
			return Binary::bytes2int($this->read(3), true);
		}
		return Binary::bytes2int($this->read(8), true);
	}

	/**
	 * Parse length-encoded string
	 * @return integer Result
	 */
	public function parseEncodedString() {
		$l = $this->parseEncodedBinary();
		if (($l === null) || ($l === false)) {
			return $l;
		}
		return $this->read($l);
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
		if ($this->phase !== self::PHASE_GOT_INIT) {
			return;
		}
		$this->phase = self::PHASE_AUTH_SENT;
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
			$packet = pack('VVc', $this->clientFlags, $this->maxPacketSize, $this->charsetNumber)
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
		
		if ($this->phase !== self::PHASE_HANDSHAKED) {
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
		$this->dbname = $name;

		if ($this->phase !== self::PHASE_GOT_INIT) {
			return $this->query('USE `' . $name . '`');
		}
		
		return TRUE;
	}

	/**
	 * Called when new data received
	 * @return void
	 */
	public function onRead() {
		packet:
		if ($this->state === self::STATE_STANDBY) {
			if ($this->bev->input->length < 4) {
				return;
			}
			$this->pctSize = Binary::bytes2int($this->read(3), true);
			$this->setWatermark($this->pctSize);
			$this->state = self::STATE_BODY;
			$this->seq = ord($this->read(1)) + 1;
		}
		/* STATE_BODY */
		$l = $this->bev->input->length;
		if ($l < $this->pctSize) {
			return;
		}
		$this->state = self::STATE_STANDBY;
		$this->setWatermark(4);
		if ($this->phase === 0) {
			$this->phase = self::PHASE_GOT_INIT;
			$this->protover = ord($this->read(1));
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
			if (($p = $this->search("\x00")) === false) {
				$this->log('nul-terminator of \'serverver\' is not found');
				$this->finish();
				return;
			}
			$this->serverver = $this->read($p);
			$this->drain(1); // drain nul-byte
			$this->threadId = Binary::bytes2int($this->read(4), true);
			$this->scramble = $this->read(8);
			$this->drain(1); // ????
	
			$this->serverCaps = Binary::bytes2int($this->read(2), true);	
			$this->serverLang = ord($this->read(1));
			$this->serverStatus = Binary::bytes2int($this->read(2), true);
			$this->drain(13);
			$restScramble = $this->read(12);
			$this->scramble .= $restScramble;
			$this->drain(1);
	
			$this->auth();
		} else {
			$fieldCount = ord($this->read(1));
			field:
			if ($fieldCount === 0xFF) {
				// Error packet
				$u = unpack('v', $this->read(2));				
				$this->errno = $u[1];
				$state = $this->read(6);
				$this->errmsg = $this->read($this->pctSize - $l + $this->bev->input->length);
				$this->onError();
				$this->errno = 0;
				$this->errmsg = '';
			}
			elseif ($fieldCount === 0x00) {
				// OK Packet Empty
				if ($this->phase === self::PHASE_AUTH_SENT) {
					$this->phase = self::PHASE_HANDSHAKED;
			
					if ($this->dbname !== '') {
						$this->query('USE `' . $this->dbname . '`');
					}
				}

				$this->affectedRows = $this->parseEncodedBinary();

				$this->insertId = $this->parseEncodedBinary();

				$u = unpack('v', $this->read(2));
				$this->serverStatus = $u[1];

				$u = unpack('v', $this->read(2));		
				$this->warnCount = $u[1];

				$this->message = $this->read($this->pctSize - $l + $this->bev->input->length);
				$this->onResultDone();
			}
			elseif ($fieldCount === 0xFE) { 
				// EOF Packet		
				if ($this->rsState === self::RS_STATE_ROW) {
					$this->onResultDone();
				}
				else {
					++$this->rsState;
				}
			} else {
				// Data packet
				$this->prependInput(chr($fieldCount));
		
				if ($this->rsState === self::RS_STATE_HEADER) {
					// Result Set Header Packet
					$extra = $this->parseEncodedBinary();
					$this->rsState = self::RS_STATE_FIELD;
				}
				elseif ($this->rsState === self::RS_STATE_FIELD) {
					// Field Packet
					$field = [
						'catalog'    => $this->parseEncodedString(),
						'db'         => $this->parseEncodedString(),
						'table'      => $this->parseEncodedString(),
						'org_table'  => $this->parseEncodedString(),
						'name'       => $this->parseEncodedString(),
						'org_name'   => $this->parseEncodedString()
					];

					$this->drain(1); // filler

					$u = unpack('v', $this->read(2));

					$field['charset'] = $u[1];
					$u = unpack('V', $this->read(4));
					$field['length'] = $u[1];

					$field['type'] = ord($this->read(1));

					$u = unpack('v', $this->read(2));
					$field['flags'] = $u[1];

					$field['decimals'] = ord($this->read(1));

					$this->resultFields[] = $field;
				}
				elseif ($this->rsState === self::RS_STATE_ROW) {
					// Row Packet
					$row = [];

					for ($i = 0, $nf = sizeof($this->resultFields); $i < $nf; ++$i) {
						$row[$this->resultFields[$i]['name']] = $this->parseEncodedString();
					}
		
					$this->resultRows[] = $row;
				}
			}
		}
		$this->drain($this->pctSize - $l + $this->bev->input->length); // drain the rest of packet
		goto packet;
	}

	/**
	 * Called when the whole result received
	 * @return void
	 */
	public function onResultDone() {
		$this->rsState = self::RS_STATE_HEADER;
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
		$this->rsState = self::RS_STATE_HEADER;
		$this->onResponse->executeOne($this, false);
		$this->checkFree();
		$this->resultRows = [];
		$this->resultFields = [];

		if (($this->phase === self::PHASE_AUTH_SENT) || ($this->phase === self::PHASE_GOT_INIT)) {
			// in case of auth error
			$this->phase = self::PHASE_AUTH_ERR;
			$this->finish();
		}
	
		Daemon::log(__METHOD__ . ' #' . $this->errno . ': ' . $this->errmsg);
	}
}
class MySQLClientConnectionFinished extends Exception {}
