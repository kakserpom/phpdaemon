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

