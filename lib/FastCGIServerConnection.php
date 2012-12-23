<?php

/**
 * @package NetworkServers
 * @subpackage Base
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class FastCGIServerConnection extends Connection {
	protected $lowMark  = 8;         // initial value of the minimal amout of bytes in buffer
	protected $highMark = 0xFFFFFF;  // initial value of the maximum amout of bytes in buffer
	public $timeout = 180;

	protected $requests = array();
	
	public $sendfileCap = false;

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
	
	public function stdin($buf) {
		$this->buf .= $buf;
		start:
		if ($this->state === self::STATE_ROOT) {
			$header = $this->readFromBufExact(8);

			if ($header === false) {
				return;
			}

			$this->header = unpack('Cver/Ctype/nreqid/nconlen/Cpadlen/Creserved', $header);

			if ($this->header['conlen'] > 0) {
				$this->setWatermark($this->header['conlen']);
			}
			$type = $this->header['type'];
			$this->header['ttype'] = isset(self::$requestTypes[$type]) ? self::$requestTypes[$type] : $type;
			$rid = $this->header['reqid'];
			$this->state = self::STATE_CONTENT;
			
		} else {
			$type = $this->header['type'];
			$rid = $this->header['reqid'];
		}
		if ($this->state === self::STATE_CONTENT) {
			$this->content = $this->readFromBufExact($this->header['conlen']);

			if ($this->content === false) {
				$this->setWatermark($this->header['conlen']);
				return;
			}

			if ($this->header['padlen'] > 0) {
				$this->setWatermark($this->header['padlen']);
			}

			$this->state = self::STATE_PADDING;
		}

		if ($this->state === self::STATE_PADDING) {
			$pad = $this->readFromBufExact($this->header['padlen']);

			if ($pad === false) {
				return;
			}
		}
		$this->setWatermark(8);
		$this->state = self::STATE_ROOT;

		
		if (0) Daemon::log('[DEBUG] FastCGI-record ' . $this->header['ttype'] . '). Request ID: ' . $rid 
				. '. Content length: ' . $this->header['conlen'] . ' (' . strlen($this->content) . ') Padding length: ' . $this->header['padlen'] 
				. ' (' . strlen($pad) . ')');
		

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
			$req->conn = $this;
			$this->requests[$rid] = $req;
		}
		elseif (isset($this->requests[$rid])) {
			$req = $this->requests[$rid];
		} else {
			Daemon::log('Unexpected FastCGI-record #' . $this->header['type'] . ' (' . $this->header['ttype'] . '). Request ID: ' . $rid . '.');
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
				$req->conn = $this;

				if ($req instanceof stdClass) {
					$this->endRequest($req, 0, 0);
					unset($this->requests[$rid]);
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

					$this->requests[$rid] = $req;
				}
			} else {
				$p = 0;

				while ($p < $this->header['conlen']) {
					if (($namelen = ord($this->content{$p})) < 128) {
						++$p;
					} else {
						$u = unpack('Nlen', chr(ord($this->content{$p}) & 0x7f) . binarySubstr($this->content, $p + 1, 3));
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
	
	/**
	 * Handles the output from downstream requests.
	 * @param object Request.
	 * @param string The output.
	 * @return void
	 */
	public function requestOut($req, $output) {		
		$outlen = strlen($output);
		
		/* 
		* Iterate over every character in the string, 
		* escaping with a slash or encoding to UTF-8 where necessary 
		*/ 
		// string bytes counter 
		$d = 0; 
		while($d < $outlen){ 
		  
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
			$c = min($this->pool->config->chunksize->value, $d - $o);
			$w = $this->write(
				  "\x01"												// protocol version
				. "\x06"												// record type (STDOUT)
				. pack('nn', $req->attrs->id, $c)					// id, content length
				. "\x00" 												// padding length
				. "\x00"												// reserved 
				. ($c === $d ? $output : substr($output, $o, $c)) // content
			);
			if ($w === false) {
				$req->abort();
				return false;
			}
			$o += $c;
		}
		return true;
	}
	public function freeRequest($req) {
		unset($this->requests[$req->attrs->id]);
	}
	/**
	 * Handles the output from downstream requests.
	 * @return void
	 */
	public function endRequest($req, $appStatus, $protoStatus) {
		$c = pack('NC', $appStatus, $protoStatus) // app status, protocol status
			. "\x00\x00\x00";


		$this->write(
			"\x01"                                     // protocol version
			. "\x03"                                   // record type (END_REQUEST)
			. pack('nn', $req->attrs->id, strlen($c))  // id, content length
			. "\x00"                                   // padding length
			. "\x00"                                   // reserved
			. $c                                       // content
		); 

		if ($protoStatus === -1) {
			$this->close();
		}
		elseif (!$this->pool->config->keepalive->value) {
			$this->finish();
		}
	}	
}

