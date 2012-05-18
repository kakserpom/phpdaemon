<?php

/**
 * @package Applications
 * @subpackage FastCGI
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class FastCGI extends AsyncServer {

	protected $initialLowMark  = 8;         // initial value of the minimal amout of bytes in buffer
	protected $initialHighMark = 0xFFFFFF;  // initial value of the maximum amout of bytes in buffer
	protected $queuedReads     = true;

	private $variablesOrder;

	const FCGI_BEGIN_REQUEST     = 1;
	const FCGI_ABORT_REQUEST     = 2;
	const FCGI_END_REQUEST       = 3;
	const FCGI_PARAMS            = 4;
	const FCGI_STDIN             = 5;
	const FCGI_STDOUT            = 6;
	const FCGI_STDERR            = 7;
	const FCGI_DATA              = 8;
	const FCGI_GET_VALUES        = 9;
	const FCGI_GET_VALUES_RESULT = 10;
	const FCGI_UNKNOWN_TYPE      = 11;
	
	const FCGI_RESPONDER         = 1;
	const FCGI_AUTHORIZER        = 2;
	const FCGI_FILTER            = 3;
	
	private static $roles = array(
		self::FCGI_RESPONDER         => 'FCGI_RESPONDER',
		self::FCGI_AUTHORIZER        => 'FCGI_AUTHORIZER',
		self::FCGI_FILTER            => 'FCGI_FILTER',
	);

	private static $requestTypes = array(
		self::FCGI_BEGIN_REQUEST     => 'FCGI_BEGIN_REQUEST',
		self::FCGI_ABORT_REQUEST     => 'FCGI_ABORT_REQUEST',
		self::FCGI_END_REQUEST       => 'FCGI_END_REQUEST',
		self::FCGI_PARAMS            => 'FCGI_PARAMS',
		self::FCGI_STDIN             => 'FCGI_STDIN',
		self::FCGI_STDOUT            => 'FCGI_STDOUT',
		self::FCGI_STDERR            => 'FCGI_STDERR',
		self::FCGI_DATA              => 'FCGI_DATA',
		self::FCGI_GET_VALUES        => 'FCGI_GET_VALUES',
		self::FCGI_GET_VALUES_RESULT => 'FCGI_GET_VALUES_RESULT',
		self::FCGI_UNKNOWN_TYPE      => 'FCGI_UNKNOWN_TYPE',
	);

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'expose'                  => 1,
			'auto-read-body-file'     => 1,
			'listen'                  =>  'tcp://127.0.0.1,unix:/tmp/phpdaemon.fcgi.sock',
			'listen-port'             => 9000,
			'allowed-clients'         => '127.0.0.1',
			'log-records'             => 0,
			'log-records-miss'        => 0,
			'log-events'              => 0,
			'log-queue'               => 0,
			'send-file'               => 0,
			'send-file-dir'           => '/dev/shm',
			'send-file-prefix'        => 'fcgi-',
			'send-file-onlybycommand' => 0,
			'keepalive'               => new Daemon_ConfigEntryTime('0s'),
			'chunksize'               => new Daemon_ConfigEntrySize('8k'),
			'defaultcharset'					=> 'utf-8',
			// disabled by default
			'enable'                  => 0
		);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->config->enable) {
			if (
				($order = ini_get('request_order')) 
				|| ($order = ini_get('variables_order'))
			) {
				$this->variablesOrder = $order;
			} else {
				$this->variablesOrder = null;
			}
			
			$this->allowedClients = explode(',', $this->config->allowedclients->value);

			$this->bindSockets(
				$this->config->listen->value,
				$this->config->listenport->value
			);
		}
	}
	/**
	 * Called when remote host is trying to establish the connection.
	 * @return boolean If true then we can accept new connections, else we can't.
	 */
	public function checkAccept($stream, $event, $arg) {
		if (Daemon::$process->reload) {
			return false;
		}

		return Daemon::$config->maxconcurrentrequestsperworker->value >= sizeof($this->queue);
	}
	
	/**
	 * Handles the output from downstream requests.
	 * @param object Request.
	 * @param string The output.
	 * @return void
	 */
	public function requestOut($request, $output) {
		
		$outlen = strlen($output);

		if ($this->config->logrecords->value) {
			Daemon::log('[DEBUG] requestOut(' . $request->attrs->id . ',[...' . $outlen . '...])');
		}

		if (!isset(Daemon::$process->pool[$request->attrs->connId])) {
			if (
				$this->config->logrecordsmiss->value
				|| $this->config->logrecords->value
			) {
				Daemon::log('[DEBUG] requestOut(' . $request->attrs->id . ',[...' . $outlen . '...]) connId ' . $connId . ' not found.');
			}

			return false;
		}
		
		/* 
		* Iterate over every character in the string, 
		* escaping with a slash or encoding to UTF-8 where necessary 
		*/ 
		// string bytes counter 
		$d = 0; 
		for ($c = 0; $c < $outlen; ++$c) { 
		  
		  $ord_var_c = ord($output{$d}); 
		  
		  switch (true) { 
			  case (($ord_var_c >= 0x20) && ($ord_var_c <= 0x7F)): 
				  // characters U-00000000 - U-0000007F (same as ASCII) 
				  $d++; 
				  break; 
			  
			  case (($ord_var_c & 0xE0) == 0xC0): 
				  // characters U-00000080 - U-000007FF, mask 110XXXXX 
				  // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8 
				  $d+=2; 
				  break; 

			  case (($ord_var_c & 0xF0) == 0xE0): 
				  // characters U-00000800 - U-0000FFFF, mask 1110XXXX 
				  // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8 
				  $d+=3; 
				  break; 

			  case (($ord_var_c & 0xF8) == 0xF0): 
				  // characters U-00010000 - U-001FFFFF, mask 11110XXX 
				  // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8 
				  $d+=4; 
				  break; 

			  case (($ord_var_c & 0xFC) == 0xF8): 
				  // characters U-00200000 - U-03FFFFFF, mask 111110XX 
				  // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8 
				  $d+=5; 
				  break; 

			  case (($ord_var_c & 0xFE) == 0xFC): 
				  // characters U-04000000 - U-7FFFFFFF, mask 1111110X 
				  // see http://www.cl.cam.ac.uk/~mgk25/unicode.html#utf-8 
				  $d+=6; 
				  break; 
			  default: 
				$d++;    
		  } 
		} 

		for ($o = 0; $o < $d;) {
			$c = min($this->config->chunksize->value, $d - $o);

			
			Daemon::$process->writePoolState[$request->attrs->connId] = true;

			$w = event_buffer_write($this->buf[$request->attrs->connId],
				  "\x01"                                                    	    // protocol version
				. "\x06"                                                      	// record type (STDOUT)
				. pack('nn', $request->attrs->id, $c)           		// id, content length
				. "\x00"                                                      	// padding length
				. "\x00"                                                   	    // reserved 
				. ($c === $d ? $output : binarySubstr($output, $o, $c))  // content
			);
			
			if ($w === false) {
				$request->abort();
				return false;
			}

			$o += $c;
		}
	}

	/**
	 * Handles the output from downstream requests.
	 * @return void
	 */
	public function endRequest($req, $appStatus, $protoStatus) {
		$connId = $req->attrs->connId;

		if ($this->config->logevents->value) {
			Daemon::$process->log('endRequest(' . implode(',', func_get_args()) . '): connId = ' . $connId . '.');
		};

		$c = pack('NC', $appStatus, $protoStatus) // app status, protocol status
			. "\x00\x00\x00";

		if (!isset($this->buf[$connId])) {return;}
		Daemon::$process->writePoolState[$connId] = true;
		$w = event_buffer_write($this->buf[$connId],
			"\x01"                                     // protocol version
			. "\x03"                                   // record type (END_REQUEST)
			. pack('nn', $req->attrs->id, strlen($c))  // id, content length
			. "\x00"                                   // padding length
			. "\x00"                                   // reserved
			. $c                                       // content
		); 

		if ($protoStatus === -1) {
			$this->closeConnection($connId);
		}
		elseif (!$this->config->keepalive->value) {
			$this->finishConnection($connId);
		}
	}

	/**
	 * Reads data from the connection's buffer.
	 * @param integer Connection's ID.
	 * @return void
	 */
	public function readConn($connId) {
		$state = sizeof($this->poolState[$connId]);

		if ($state === 0) {
			$header = $this->read($connId, 8);

			if ($header === false) {
				return;
			}

			$r = unpack('Cver/Ctype/nreqid/nconlen/Cpadlen/Creserved', $header);

			if ($r['conlen'] > 0) {
				event_buffer_watermark_set($this->buf[$connId], EV_READ, $r['conlen'], 0xFFFFFF);
			}

			$this->poolState[$connId][0] = $r;

			++$state;
		} else {
			$r = $this->poolState[$connId][0];
		}

		if ($state === 1) {
			$c = ($r['conlen'] === 0) ? '' : $this->read($connId, $r['conlen']);

			if ($c === false) {
				return;
			}

			if ($r['padlen'] > 0) {
				event_buffer_watermark_set($this->buf[$connId], EV_READ, $r['padlen'], 0xFFFFFF);
			}

			$this->poolState[$connId][1] = $c;

			++$state;
		} else {
			$c = $this->poolState[$connId][1];
		}

		if ($state === 2) {
			$pad = ($r['padlen'] === 0) ? '' : $this->read($connId, $r['padlen']);

			if ($pad === false) {
				return;
			}

			$this->poolState[$connId][2] = $pad;
		} else {
			$pad = $this->poolState[$connId][2];
		}

		$this->poolState[$connId] = array();
		$type = &$r['type'];
		$r['ttype'] = isset(self::$requestTypes[$type]) ? self::$requestTypes[$type] : $type;
		$rid = $connId . '-' . $r['reqid'];

		if ($this->config->logrecords->value) {
			Daemon::log('[DEBUG] FastCGI-record #' . $r['type'] . ' (' . $r['ttype'] . '). Request ID: ' . $rid 
				. '. Content length: ' . $r['conlen'] . ' (' . strlen($c) . ') Padding length: ' . $r['padlen'] 
				. ' (' . strlen($pad) . ')');
		}

		if ($type == self::FCGI_BEGIN_REQUEST) {
			++Daemon::$process->reqCounter;
			$rr = unpack('nrole/Cflags',$c);

			$req = new stdClass();
			$req->attrs = new stdClass();
			$req->attrs->request     = array();
			$req->attrs->get         = array();
			$req->attrs->post        = array();
			$req->attrs->cookie      = array();
			$req->attrs->server      = array();
			$req->attrs->files       = array();
			$req->attrs->session     = null;
			$req->attrs->connId      = $connId;
			$req->attrs->trole       = self::$roles[$rr['role']];
			$req->attrs->flags       = $rr['flags'];
			$req->attrs->id          = $r['reqid'];
			$req->attrs->params_done = false;
			$req->attrs->stdin_done  = false;
			$req->attrs->stdinbuf    = '';
			$req->attrs->stdinlen    = 0;
			$req->attrs->chunked     = false;
			$req->attrs->noHttpVer   = true;
			$req->queueId = $rid;

			if ($this->config->logqueue->value) {
				Daemon::$process->log('new request queued.');
			}

			Daemon::$process->queue[$rid] = $req;

			$this->poolQueue[$connId][$req->attrs->id] = $req;
		}
		elseif (isset(Daemon::$process->queue[$rid])) {
			$req = Daemon::$process->queue[$rid];
		} else {
			Daemon::log('Unexpected FastCGI-record #' . $r['type'] . ' (' . $r['ttype'] . '). Request ID: ' . $rid . '.');
			return;
		}

		if ($type === self::FCGI_ABORT_REQUEST) {
			$req->abort();
		}
		elseif ($type === self::FCGI_PARAMS) {
			if ($c === '') {
				if (!isset($req->attrs->server['REQUEST_TIME'])) {
					$req->attrs->server['REQUEST_TIME'] = time();
				}
				if (!isset($req->attrs->server['REQUEST_TIME_FLOAT'])) {
					$req->attrs->server['REQUEST_TIME_FLOAT'] = microtime(true);	
				}
				$req->attrs->params_done = true;

				$req = Daemon::$appResolver->getRequest($req, $this);

				if ($req instanceof stdClass) {
					$this->endRequest($req, 0, 0);
					unset(Daemon::$process->queue[$rid]);
				} else {
					if (
						$this->config->sendfile->value
						&& (
							!$this->config->sendfileonlybycommand->value
							|| isset($req->attrs->server['USE_SENDFILE'])
						) 
						&& !isset($req->attrs->server['DONT_USE_SENDFILE'])
					) {
						$fn = tempnam(
							$this->config->sendfiledir->value,
							$this->config->sendfileprefix->value
						);

						$req->sendfp = fopen($fn, 'wb');
						$req->header('X-Sendfile: ' . $fn);
					}

					Daemon::$process->queue[$rid] = $req;
				}
			} else {
				$p = 0;

				while ($p < $r['conlen']) {
					if (($namelen = ord($c{$p})) < 128) {
						++$p;
					} else {
						$u = unpack('Nlen', chr(ord($c{$p}) & 0x7f) . binarySubstr($c, $p + 1, 3));
						$namelen = $u['len'];
						$p += 4;
					}

					if (($vlen = ord($c{$p})) < 128) {
						++$p;
					} else {
						$u = unpack('Nlen', chr(ord($c{$p}) & 0x7f) . binarySubstr($c, $p + 1, 3));
						$vlen = $u['len'];
						$p += 4;
					}

					$req->attrs->server[binarySubstr($c, $p, $namelen)] = binarySubstr($c, $p + $namelen, $vlen);
					$p += $namelen + $vlen;
				}
			}
		}
		elseif ($type === self::FCGI_STDIN) {
			if ($c === '') {
				$req->attrs->stdin_done = true;
			}

			$req->stdin($c);
		}

		if (
			$req->attrs->stdin_done 
			&& $req->attrs->params_done
		) {
			if ($this->variablesOrder === null) {
				$req->attrs->request = $req->attrs->get + $req->attrs->post + $req->attrs->cookie;
			} else {
				for ($i = 0, $s = strlen($this->variablesOrder); $i < $s; ++$i) {
					$char = $this->variablesOrder[$i];

					if ($char == 'G') {
						$req->attrs->request += $req->attrs->get;
					}
					elseif ($char == 'P') {
						$req->attrs->request += $req->attrs->post;
					}
					elseif ($char == 'C') {
						$req->attrs->request += $req->attrs->cookie;
					}
				}
			}

			Daemon::$process->timeLastReq = time();
		}
	}
	
}
