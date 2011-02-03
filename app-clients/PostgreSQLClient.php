<?php

/**
 * @package Applications
 * @subpackage PostgreSQLClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class PostgreSQLClient extends AsyncServer {

	public $sessions = array(); // Active sessions
	public $servConn = array(); // Active connections

	public $ready = FALSE; // Ready?

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// default server
			'server' => 'pg://root@127.0.0.1',
			// default port
			'port'   => 5432,
			// @todo add description
			'protologging' => 0,
			// disabled by default
			'enable' => 0
		);
	}

	/**
	 * Constructor
	 * @return void
	 */
	public function init() {
		if ($this->config->enable->value) {
			$this->ready = TRUE;
		}
	}

	/**
	 * Establish connection
	 * @param string Address
	 * @return integer Connection's ID
	 */
	public function getConnection($addr = NULL) {
		if (!$this->ready) {
			return FALSE;
		}
		
		if (empty($addr)) {
			$addr = $this->config->server->value;
		}
		
		if (isset($this->servConn[$addr])) {
			foreach ($this->servConn[$addr] as &$c) {
				if (
					isset($this->sessions[$c]) 
					&& !sizeof($this->sessions[$c]->callbacks)
				) {
					return $this->sessions[$c];
				}
			}
		} else {
			$this->servConn[$addr] = array();
		}
		
		$u = parse_url($addr);
		
		if (!isset($u['port'])) {
			$u['port'] = $this->config->port->value;
		}
		
		$connId = $this->connectTo($u['host'], $u['port']);

		if (!$connId) {
			return;
		}
		
		$this->sessions[$connId] = new PostgreSQLClientSession($connId, $this);
		$this->sessions[$connId]->url = $addr;
		
		if (isset($u['user'])) {
			$this->sessions[$connId]->user = $u['user'];
		}
		
		if (isset($u['pass'])) {
			$this->sessions[$connId]->password = $u['pass'];
		}
		
		if (isset($u['path'])) {
			$this->sessions[$connId]->dbname = ltrim($u['path'], '/');
		}
		
		$this->servConn[$addr][$connId] = $connId;
		
		return $this->sessions[$connId];
	}
}

class PostgreSQLClientSession extends SocketSession {
	public $url;                       // Connection's URL.
	public $protover      = '3.0';
	public $maxPacketSize = 0x1000000; // Maximum packet size.
	public $charsetNumber = 0x08;      // Charset number.
	public $dbname        = '';        // Default database name.
	public $user          = 'root';    // Username
	public $password      = '';        // Password
	public $options       = '';        // Default options
	public $cstate        = 0;         // Connection's state. 0 - start,  1 - got initial packet,  2 - auth. packet sent,  3 - auth. error,  4 - handshaked OK
	public $instate       = 0;         // State of pointer of incoming data. 0 - Result Set Header Packet,  1 - Field Packet,  2 - Row Packet
	public $resultRows    = array();   // Resulting rows.
	public $resultFields  = array();   // Resulting fields
	public $callbacks     = array();   // Stack of callbacks.
	public $onConnected   = array();   // Callback. Called when connection's handshaked.
	public $context;                   // Property holds a reference to user's object.
	public $insertId;                  // Equals with INSERT_ID().
	public $insertNum;                 // Equals with INSERT_ID().
	public $affectedRows;              // Number of affected rows.
	public $ready         = FALSE;
	public $parameters    = array();   // Runtime parameters from server
	public $backendKey;

	/**
	 * Called when the connection is ready to accept new data
	 * @return void
	 */
	public function onWrite() {
		if (!$this->ready) {
			$this->ready = TRUE;
			$e = explode('.', $this->protover);
			$packet = pack('nn', $e[0], $e[1]);
	
			if (strlen($this->user)) {
				$packet .= "user\x00" . $this->user . "\x00";
			}
			
			if (strlen($this->dbname)) {
				$packet .= "database\x00" . $this->dbname . "\x00";
			}
			
			if (strlen($this->options)) {
				$packet .= "options\x00" . $this->options . "\x00";
			}
			
			$packet .= "\x00";
			
			$this->sendPacket('', $packet);
		}
	}

	/**
	 * Executes the given callback when/if the connection is handshaked.
	 * Callback.
	 * @return void
	 */
	public function onConnected($callback) {
		$this->onConnected[] = $callback;

		if ($this->cstate == 3) {
			call_user_func($callback, $this, FALSE);
		}
		elseif ($this->cstate === 4) {
			call_user_func($callback, $this, TRUE);
		}
	}

	/** 
	 * Converts binary string to integer
	 * @param string Binary string
	 * @param boolean Optional. Little endian. Default value - true.
	 * @return integer Resulting integer
	 */
	public function bytes2int($str, $l = TRUE) {
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
	 * Send a packet
	 * @param string Data
	 * @return boolean Success
	 */
	public function sendPacket($type = '',  $packet) {
		$header = $type . pack('N', strlen($packet) + 4); 

		$this->write($header);
		$this->write($packet);

		if ($this->appInstance->config->protologging->value) {
			Daemon::log('Client --> Server: ' . Debug::exportBytes($header . $packet) . "\n\n");
		}
		
		return TRUE;
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
		
		if ($s === 251) {
			return NULL;
		}
		
		if ($s === 255) {
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
	public function parseEncodedString(&$s, &$p) {
		$l = $this->parseEncodedBinary($s, $p);

		if (
			($l === NULL) 
			|| ($l === FALSE)
		) {
			return $l;
		}
		
		$o = $p;
		$p += $l;
		
		return binarySubstr($s, $o, $l);
	}

	/**
	 * Send SQL-query
	 * @param string Query
	 * @param callback Optional. Callback called when response received.
	 * @return boolean Success
	 */
	public function query($q, $callback = NULL) {
		return $this->command('Q', $q . "\x00", $callback);
	}

	/** 
	 * Send echo-request
	 * @param callback Optional. Callback called when response received
	 * @return boolean Success
	 */
	public function ping($callback = NULL) {
		// @todo ???????
		//return $this->command(, '', $callback);
	}

	/**
	 * Sends sync-request
	 * @param callback Optional. Callback called when response received.
	 * @return boolean Success
	 */
	public function sync($callback = NULL) {
		return $this->command('S', '', $callback);
	}

	/**
	 * Send terminate-request to shutdown the connection
	 * @return boolean Success
	 */
	public function terminate() {
		return $this->command('X', '', $callback);
	}

	/**
	 * Sends arbitrary command
	 * @param integer Command's code. See constants above.
	 * @param string Data
	 * @param callback Optional. Callback called when response received.
	 * @return boolean Success
	 */
	public function command($cmd, $q = '', $callback = NULL) {
		if ($this->cstate !== 4) {
			return FALSE;
		}
		
		$this->callbacks[] = $callback;
		$this->sendPacket($cmd, $q);
		
		return TRUE;
	}

	/**
	 * Set default database name
	 * @param string Database name
	 * @return boolean Success
	 */
	public function selectDB($name) {
		$this->dbname = $name;

		if ($this->cstate !== 1) {
			return $this->query('USE `' . $name . '`');
		}

		return TRUE;
	}

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;

		if ($this->appInstance->config->protologging->value) {
			Daemon::log('Server --> Client: ' . Debug::exportBytes($buf) . "\n\n");
		}
		
		start:
		
		$this->buflen = strlen($this->buf);
		
		if ($this->buflen < 5) {
			// Not enough data buffered yet
			return;
		}
		
		$type = binarySubstr($this->buf, 0, 1);
		
		list(, $length) = unpack('N', binarySubstr($this->buf, 1, 4));
		$length -= 4;
		
		if ($this->buflen < 5 + $length) {
			// Not enough data buffered yet
			return;
		}
		
		$packet = binarySubstr($this->buf, 5, $length);
		$this->buf = binarySubstr($this->buf, 5 + $length);
		
		if ($type === 'R') {
			// Authentication request
			list(, $authType) = unpack('N', $packet);
	
			if ($authType === 0) {
				// Successful
				if ($this->appInstance->config->protologging->value) {
					Daemon::log(__CLASS__ . ': auth. ok.');
				}

				$this->cstate = 4; // Auth. ok

				foreach ($this->onConnected as $cb) {
					call_user_func($cb, $this, TRUE);
				}
			} // @todo move to constant values
			elseif ($authType === 2) {
				// KerberosV5
				Daemon::log(__CLASS__ . ': Unsupported authentication method: KerberosV5.');
				$this->cstate = 3; // Auth. error
				$this->finish(); // Unsupported,  finish
			}
			elseif ($authType === 3) {
				// Cleartext
				$this->sendPacket('p', $this->password); // Password Message
				$this->cstate = 2; // Auth. packet sent
			}
			elseif ($authType === 4) {
				// Crypt
				$salt = binarySubstr($packet, 4, 2);
				$this->sendPacket('p', crypt($this->password, $salt)); // Password Message
				$this->cstate = 2; // Auth. packet sent
			}
			elseif ($authType === 5) {
				// MD5
				$salt = binarySubstr($packet, 4, 4);
				$this->sendPacket('p', 'md5' . md5(md5($this->password . $this->user) . $salt)); // Password Message
				$this->cstate = 2; // Auth. packet sent
			}
			elseif ($authType === 6) {
				// SCM
				Daemon::log(__CLASS__ . ': Unsupported authentication method: SCM.');
				$this->cstate = 3; // Auth. error
				$this->finish(); // Unsupported,  finish
			}
			elseif ($authType == 9) {
				// GSS
				Daemon::log(__CLASS__.': Unsupported authentication method: GSS.');
				$this->cstate = 3; // Auth. error
				$this->finish(); // Unsupported,  finish
			}
		}
		elseif ($type === 'T') {
			// Row Description
			list(, $numfields) = unpack('n', binarySubstr($packet, 0, 2));
			$p = 2;
	
			for ($i = 0; $i < $numfields; ++$i) {
				list($name) = $this->decodeNULstrings($packet, 1, $p);
				$field = unpack('NtableOID/nattrNo/NdataType/ndataTypeSize/NtypeMod/nformat', binarySubstr($packet, $p, 18));
				$p += 18;
				$field['name'] = $name;
				$this->resultFields[] = $field;
			}
		}
		elseif ($type === 'D') {
			// Data Row
			list(, $numfields) = unpack('n', binarySubstr($packet, 0, 2));
			$p = 2;
			$row = array();

			for ($i = 0; $i < $numfields; ++$i) {
				list(, $length) = unpack('N', binarySubstr($packet, $p, 4));
				$p += 4;

				if ($length === 0xffffffff) {
					// hack
					$length = -1;
				} 
				
				if ($length === -1) {
					$value = NULL;
				} else { 
					$value = binarySubstr($packet, $p, $length);
					$p += $length;
				}
	
				$row[$this->resultFields[$i]['name']] = $value;
			}
	
			$this->resultRows[] = $row;
		}
		elseif (
			$type === 'G'
			|| $type === 'H'
		) {
			// Copy in response
			// The backend is ready to copy data from the frontend to a table; see Section 45.2.5.
			if ($this->appInstance->config->protologging->value) {
				Daemon::log(__CLASS__ . ': Caught CopyInResponse');
			}
		}
		elseif ($type === 'C') {
			// Close command
			$type = binarySubstr($packet, 0, 1);
	
			if (
				($type === 'S') 
				|| ($type === 'P')
			) {
				list($name) = $this->decodeNULstrings(binarySubstr($packet, 1));
			} else {
				$tag = $this->decodeNULstrings($packet);
				$tag = explode(' ', $tag[0]);

				if ($tag[0] === 'INSERT') {
					$this->insertId = $tag[1];
					$this->insertNum = $tag[2];
				}
				elseif (
					($tag[0] === 'DELETE') 
					|| ($tag[0] === 'UPDATE') 
					|| ($tag[0] === 'MOVE') 
					|| ($tag[0] === 'FETCH') 
					|| ($tag[0] === 'COPY')
				) {
					$this->affectedRows = $tag[1];
				}
			}
	
			$this->onResultDone();
		}
		elseif ($type === 'n') {
			// No Data
			$this->onResultDone();
		}
		elseif ($type === 'E') {
			// Error Response
			$code = ord($packet);
			$message = '';

			foreach ($this->decodeNULstrings(binarySubstr($packet, 1), 0xFF) as $p) {
				if ($message !== '') {
					$message .= ' ';
					$p = binarySubstr($p, 1);
				}
				
				$message .= $p;
			}
			
			$this->errno = -1;
			$this->errmsg = $message;

			if ($this->cstate == 2) {
				// Auth. error
				foreach ($this->onConnected as $cb) {
					call_user_func($cb, $this, FALSE);
				}

				$this->cstate = 3;
			}
			
			$this->onError();
		
			if ($this->appInstance->config->protologging->value) {
				Daemon::log(__CLASS__ . ': Error response caught (0x' . dechex($code) . '): ' . $message);
			}
		}
		elseif ($type === 'I') {
			// Empty Query Response
			$this->errno = -1;
			$this->errmsg = 'Query was empty';
			$this->onError();
		}
		elseif ($type === 'S') {
			// Portal Suspended
			if ($this->appInstance->config->protologging->value) {
				Daemon::log(__CLASS__ . ': Caught PortalSuspended');
			}
		}
		elseif ($type === 'S') {
			// Parameter Status
			$u = $this->decodeNULstrings($packet, 2);
	
			if (isset($u[0])) {
				$this->parameters[$u[0]] = isset($u[1]) ? $u[1] : NULL;

				if ($this->appInstance->config->protologging->value) {
					Daemon::log(__CLASS__ . ': Parameter ' . $u[0] . ' = \'' . $this->parameters[$u[0]] . '\'');
				}
			}
		}
		elseif ($type === 'K') {
			// Backend Key Data
			list(, $this->backendKey) = unpack('N', $packet);
			$this->backendKey = isset($u[1])?$u[1]:NULL;
	
			if ($this->appInstance->config->protologging->value) {
				Daemon::log(__CLASS__ . ': BackendKey is ' . $this->backendKey);
			}
		}
		elseif ($type === 'Z') {
			// Ready For Query
			$this->status = $packet;
		
			if ($this->appInstance->config->protologging->value) {
				Daemon::log(__CLASS__ . ': Ready For Query. Status: ' . $this->status);
			}
		} else {
			Daemon::log(__CLASS__ . ': Caught message with unsupported type - ' . $type);
		}
		
		goto start;
	}

	/**
	 * Decode strings from the NUL-terminated representation
	 * @param string Binary data
	 * @param integer Optional. Limit of count. Default is 1.
	 * @param reference Optional. Pointer.
	 * @return array Decoded strings
	 */
	public function decodeNULstrings($data, $limit = 1, &$p = 0) {
		$r = array();

		for ($i = 0; $i < $limit; ++$i) {
			$pos = strpos($data, "\x00", $p);

			if ($pos === FALSE) {
				break;
			}
			
			$r[] = binarySubstr($data, $p, $pos - $p);

			$p = $pos + 1;
		}
		
		return $r;
	}

	/**
	 * Called when the whole result received
	 * @return void
	 */
	public function onResultDone() {
		$this->instate = 0;
		$callback = array_shift($this->callbacks);

		if (
			$callback 
			&& is_callable($callback)
		) {
			call_user_func($callback, $this, TRUE);
		}
		
		$this->resultRows = array();
		$this->resultFields = array();
		
		if ($this->appInstance->config->protologging->value) {
			Daemon::log(__METHOD__);
		}
	}

	/**
	 * Called when error occured
	 * @return void
	 */
	public function onError() {
		$this->instate = 0;
		$callback = array_shift($this->callbacks);

		if (
			$callback 
			&& is_callable($callback)
		) {
			call_user_func($callback, $this, FALSE);
		}
		
		$this->resultRows = array();
		$this->resultFields = array();

		if ($this->cstate === 2) {
			// in case of auth error
			$this->cstate = 3;
			$this->finish();
		}
	
		Daemon::log(__METHOD__ . ' #' . $this->errno . ': ' . $this->errmsg);
	}

	/**
	 * Called when session finishes
	 * @return void
	 */
	public function onFinish() {
		$this->finished = TRUE;
	
		unset($this->servConn[$this->url][$this->connId]);
		unset($this->appInstance->sessions[$this->connId]);
	}
}

class PostgreSQLClientSessionFinished extends Exception {}
