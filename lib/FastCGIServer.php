<?php

/**
 * @package NetworkServers
 * @subpackage Base
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class FastCGIServer extends NetworkServer {
	public $variablesOrder;
	
	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// @todo add description strings
			'expose'                  => 1,
			'auto-read-body-file'     => 1,
			'listen'                  =>  '127.0.0.1,unix:/tmp/phpdaemon.fcgi.sock',
			'listen-port'             => 9000,
			'allowed-clients'         => '127.0.0.1',
			'send-file'               => 0,
			'send-file-dir'           => '/dev/shm',
			'send-file-prefix'        => 'fcgi-',
			'send-file-onlybycommand' => 0,
			'keepalive'               => new Daemon_ConfigEntryTime('0s'),
			'chunksize'               => new Daemon_ConfigEntrySize('8k'),
			'defaultcharset'		=> 'utf-8',
		);
	}
	
	/**
	 * Called when worker is going to update configuration.
	 * @return void
	 */
	public function onConfigUpdated() {
		parent::onConfigUpdated();
		if (
			($order = ini_get('request_order')) 
			|| ($order = ini_get('variables_order'))
		) {
			$this->variablesOrder = $order;
		} else {
			$this->variablesOrder = null;
		}
		
	}
	
	/**
	 * Handles the output from downstream requests.
	 * @param object Request.
	 * @param string The output.
	 * @return void
	 */
	public function requestOut($req, $output) {		
		$outlen = strlen($output);

		$conn = $this->getConnectionById($req->attrs->connId);

		if (!$conn) {
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
			$w = $conn->write(
				  "\x01"												// protocol version
				. "\x06"												// record type (STDOUT)
				. pack('nn', $req->attrs->id, $c)					// id, content length
				. "\x00" 												// padding length
				. "\x00"												// reserved 
				. ($c === $d ? $output : binarySubstr($output, $o, $c)) // content
			);
			if ($w === false) {
				$req->abort();
				return false;
			}
			$o += $c;
		}
		return true;
	}

	/**
	 * Handles the output from downstream requests.
	 * @return void
	 */
	public function endRequest($req, $appStatus, $protoStatus) {
		$conn = $this->getConnectionById($req->attrs->connId);

		if (!$conn) {
			return false;
		}
		$c = pack('NC', $appStatus, $protoStatus) // app status, protocol status
			. "\x00\x00\x00";


		$w = $conn->write(
			"\x01"                                     // protocol version
			. "\x03"                                   // record type (END_REQUEST)
			. pack('nn', $req->attrs->id, strlen($c))  // id, content length
			. "\x00"                                   // padding length
			. "\x00"                                   // reserved
			. $c                                       // content
		); 

		if ($protoStatus === -1) {
			$conn->close();
		}
		elseif (!$this->config->keepalive->value) {
			$conn->finish();
		}
	}
	
}

class FastCGIServerConnection extends Connection {
	protected $initialLowMark  = 8;         // initial value of the minimal amout of bytes in buffer
	protected $initialHighMark = 0xFFFFFF;  // initial value of the maximum amout of bytes in buffer

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

	const STATE_CONTENT = 1;
	const STATE_PADDING = 2;
	
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
	
	private $header;
	private $content;

	/**
	 * Called when new data received.
	 * @return void
	 */
	
	public function onRead() {
		start:
		if ($this->state === self::STATE_ROOT) {
			$header = $this->read(8);

			if ($header === false) {
				return;
			}

			$this->header = unpack('Cver/Ctype/nreqid/nconlen/Cpadlen/Creserved', $header);

			if ($this->header['conlen'] > 0) {
				$this->setReadWatermark($this->header['conlen'], 0xFFFFFF);
			}
			$type = $this->header['type'];
			$this->header['ttype'] = isset(self::$requestTypes[$type]) ? self::$requestTypes[$type] : $type;
			$rid = $this->connId . '-' . $this->header['reqid'];
			$this->state = self::STATE_CONTENT;
			
		} else {
			$type = $this->header['type'];
		}
		if ($this->state === self::STATE_CONTENT) {
			$this->content = ($this->header['conlen'] === 0) ? '' : $this->read($this->header['conlen']);

			if ($this->content === false) {
				return;
			}

			if ($this->header['padlen'] > 0) {
				$this->setReadWatermark($this->header['padlen'], 0xFFFFFF);
			}

			$this->state = self::STATE_PADDING;
		}

		if ($this->state === self::STATE_PADDING) {
			$pad = ($this->header['padlen'] === 0) ? '' : $this->read($this->header['padlen']);

			if ($pad === false) {
				return;
			}
		}

		$this->state = self::STATE_ROOT;

		/*
			Daemon::log('[DEBUG] FastCGI-record #' . $r['type'] . ' (' . $r['ttype'] . '). Request ID: ' . $rid 
				. '. Content length: ' . $r['conlen'] . ' (' . strlen($c) . ') Padding length: ' . $r['padlen'] 
				. ' (' . strlen($pad) . ')');
		*/

		if ($type == self::FCGI_BEGIN_REQUEST) {
			++Daemon::$process->reqCounter;
			$u = unpack('nrole/Cflags', $this->content);

			$req = new stdClass();
			$req->attrs = new stdClass();
			$req->attrs->request     = array();
			$req->attrs->get         = array();
			$req->attrs->post        = array();
			$req->attrs->cookie      = array();
			$req->attrs->server      = array();
			$req->attrs->files       = array();
			$req->attrs->session     = null;
			$req->attrs->connId      = $this->connId;
			$req->attrs->role       = self::$roles[$u['role']];
			$req->attrs->flags       = $u['flags'];
			$req->attrs->id          = $this->header['reqid'];
			$req->attrs->params_done = false;
			$req->attrs->stdin_done  = false;
			$req->attrs->stdinbuf    = '';
			$req->attrs->stdinlen    = 0;
			$req->attrs->chunked     = false;
			$req->attrs->noHttpVer   = true;
			$req->queueId = $rid;

			Daemon::$process->queue[$rid] = $req;
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
			if ($this->content === '') {
				if (!isset($req->attrs->server['REQUEST_TIME'])) {
					$req->attrs->server['REQUEST_TIME'] = time();
				}
				if (!isset($req->attrs->server['REQUEST_TIME_FLOAT'])) {
					$req->attrs->server['REQUEST_TIME_FLOAT'] = microtime(true);	
				}
				$req->attrs->params_done = true;

				$req = Daemon::$appResolver->getRequest($req, $this->pool);

				if ($req instanceof stdClass) {
					$this->endRequest($req, 0, 0);
					unset(Daemon::$process->queue[$rid]);
				} else {
					if (
						$this->pool->config->sendfile->value
						&& (
							!$this->pool->config->sendfileonlybycommand->value
							|| isset($req->attrs->server['USE_SENDFILE'])
						) 
						&& !isset($req->attrs->server['DONT_USE_SENDFILE'])
					) {
						$fn = tempnam(
							$this->pool->config->sendfiledir->value,
							$this->pool->config->sendfileprefix->value
						);

						$req->sendfp = fopen($fn, 'wb');
						$req->header('X-Sendfile: ' . $fn);
					}

					Daemon::$process->queue[$rid] = $req;
				}
			} else {
				$p = 0;

				while ($p < $this->header['conlen']) {
					if (($namelen = ord($this->content{$p})) < 128) {
						++$p;
					} else {
						$u = unpack('Nlen', chr(ord($c{$p}) & 0x7f) . binarySubstr($this->content, $p + 1, 3));
						$namelen = $u['len'];
						$p += 4;
					}

					if (($vlen = ord($this->content{$p})) < 128) {
						++$p;
					} else {
						$u = unpack('Nlen', chr(ord($this->content{$p}) & 0x7f) . binarySubstr($this->content, $p + 1, 3));
						$vlen = $u['len'];
						$p += 4;
					}

					$req->attrs->server[binarySubstr($this->content, $p, $namelen)] = binarySubstr($this->content, $p + $namelen, $vlen);
					$p += $namelen + $vlen;
				}
			}
		}
		elseif ($type === self::FCGI_STDIN) {
			if ($this->content === '') {
				$req->attrs->stdin_done = true;
			}

			$req->stdin($this->content);
		}

		if (
			$req->attrs->stdin_done 
			&& $req->attrs->params_done
		) {
			if ($this->pool->variablesOrder === null) {
				$req->attrs->request = $req->attrs->get + $req->attrs->post + $req->attrs->cookie;
			} else {
				for ($i = 0, $s = strlen($this->pool->variablesOrder); $i < $s; ++$i) {
					$char = $this->pool->variablesOrder[$i];

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
		goto start;
	}
	
}
