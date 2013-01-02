<?php

/**
 * @package NetworkServers
 * @subpackage Base
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

class HTTPServerConnection extends Connection {
	protected $initialLowMark  = 1;         // initial value of the minimal amout of bytes in buffer
	protected $initialHighMark = 0xFFFFFF;  // initial value of the maximum amout of bytes in buffer
	public $timeout = 45;

	public $req;
	
	const STATE_HEADERS = 1;
	const STATE_CONTENT = 2;

	
	public $sendfileCap = true; // we can use sendfile() with this kind of connection
	public $bufHead = '';

	/**
	 * Called when new data received.
	 * @return void
	 */
	
	public function onRead() {
		start:
		$buf = $this->bufHead . $this->read($this->readPacketSize);
		$this->bufHead = '';

		if ($this->state === self::STATE_ROOT) {
			if (strlen($buf) === 0) {
				return;
			}

			if ($this->req !== null) { // we have to wait the current request.
				$this->bufHead = $buf;
				return;
			}

			if (strpos($buf, "<policy-file-request/>\x00") !== false) {
				if (
					($FP = FlashPolicyServer::getInstance()) 
					&& $FP->policyData
				) {
					$this->write($FP->policyData . "\x00");
				}
				$this->finish();
				return;
			}
			$this->state = self::STATE_HEADERS;

			$req = new stdClass();
			$req->attrs = new stdClass();
			$req->attrs->request = array();
			$req->attrs->get = array();
			$req->attrs->post = array();
			$req->attrs->cookie = array();
			$req->attrs->server = array();
			$req->attrs->files = array();
			$req->attrs->session = null;
			$req->attrs->params_done = false;
			$req->attrs->stdin_done = false;
			$req->attrs->stdinbuf = '';
			$req->attrs->stdinlen = 0;
			$req->attrs->chunked = false;
			$req->conn = $this;

			$this->req = $req;

		} else {
			if (!$this->req) {
				Daemon::log('Unexpected input (HTTP request).');
				return;
			}
			$req = $this->req;
		}

		if ($this->state === self::STATE_HEADERS) {
			if (($p = strpos($buf, "\r\n\r\n")) !== false) {
				$headers = binarySubstr($buf, 0, $p);
				$headersArray = explode("\r\n", $headers);
				$buf = binarySubstr($buf, $p + 4);
				$command = explode(' ', $headersArray[0]);
				$u = isset($command[1]) ? parse_url($command[1]) : false;
				if ($u === false) {
					$this->badRequest($req);
					return;
				}

				$req->attrs->server['REQUEST_METHOD'] = $command[0];
				$req->attrs->server['REQUEST_TIME'] = time();
				$req->attrs->server['REQUEST_TIME_FLOAT'] = microtime(true);
				$req->attrs->server['REQUEST_URI'] = $u['path'] . (isset($u['query']) ? '?' . $u['query'] : '');
				$req->attrs->server['DOCUMENT_URI'] = $u['path'];
				$req->attrs->server['PHP_SELF'] = $u['path'];
				$req->attrs->server['QUERY_STRING'] = isset($u['query']) ? $u['query'] : null;
				$req->attrs->server['SCRIPT_NAME'] = $req->attrs->server['DOCUMENT_URI'] = isset($u['path']) ? $u['path'] : '/';
				$req->attrs->server['SERVER_PROTOCOL'] = isset($command[2]) ? $command[2] : 'HTTP/1.1';

				$req->attrs->server['REMOTE_ADDR'] = $this->addr;
				$req->attrs->server['REMOTE_PORT'] = $this->port;

				for ($i = 1, $n = sizeof($headersArray); $i < $n; ++$i) {
					$e = explode(': ', $headersArray[$i]);
					if (isset($e[1])) {
						$req->attrs->server['HTTP_' . strtoupper(strtr($e[0], HTTPRequest::$htr))] = $e[1];
					}
				}
				if (!isset($req->attrs->server['HTTP_CONTENT_LENGTH'])) {
					$req->attrs->server['HTTP_CONTENT_LENGTH'] = 0;
				}
				if (isset($u['host'])) {
					$req->attrs->server['HTTP_HOST'] = $u['host'];	
				}

				$req->attrs->params_done = true;

				if (
					isset($req->attrs->server['HTTP_CONNECTION']) 
					&& preg_match('~(?:^|\W)Upgrade(?:\W|$)~i', $req->attrs->server['HTTP_CONNECTION'])
					&& isset($req->attrs->server['HTTP_UPGRADE'])
					&& (strtolower($req->attrs->server['HTTP_UPGRADE']) === 'websocket')
				) {
					if ($this->pool->WS) {
						$this->pool->WS->inheritFromRequest($req, $this->pool);
						return;
					} else {
						$this->finish();
						return;
					}
				} else {
					$req = Daemon::$appResolver->getRequest($req, $this->pool, isset($this->pool->config->responder->value) ? $this->pool->config->responder->value : null);
					$req->conn = $this;
					$this->req = $req;
				}

				if ($req instanceof stdClass) {
					$this->endRequest($req, 0, 0);
				} else {
					if ($this->pool->config->sendfile->value && (!$this->pool->config->sendfileonlybycommand->value	|| isset($req->attrs->server['USE_SENDFILE'])) 
						&& !isset($req->attrs->server['DONT_USE_SENDFILE'])
					) {
						$fn = FS::tempnam($this->pool->config->sendfiledir->value, $this->pool->config->sendfileprefix->value);
						FS::open($fn, 'wb', function ($file) use ($req) {
							$req->sendfp = $file;
						});
						$req->header('X-Sendfile: ' . $fn);
					}
					$this->state = self::STATE_CONTENT;
				}
			}
			else {
				$this->bufHead = $buf;
				return; // not enough data
			}
		}
		if ($this->state === self::STATE_CONTENT) {
			$e = $req->attrs->server['HTTP_CONTENT_LENGTH'] - strlen($buf) - $req->attrs->stdinlen;
			if ($e < 0) {
				$this->bufHead = binarySubstr($buf, $e);
				$buf = binarySubstr($buf, 0, $e);
			}
			$req->stdin($buf);
			$buf = '';
			if ($req->attrs->stdin_done) {
				$this->state = self::STATE_ROOT;
			} else {
				return;
			}
		}

		if ($req->attrs->stdin_done && $req->attrs->params_done) {
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
	 * @return boolean Success.
	 */
	public function requestOut($req, $s) {
		if ($this->write($s) === false) {
			$req->abort();
			return false;
		}
		return true;
	}

	/**
	 * Handles the output from downstream requests.
	 * @return boolean Succcess.
	 */
	public function endRequest($req, $appStatus, $protoStatus) {
		if ($protoStatus === -1) {
			$this->close();
		} else {
			if ($req->attrs->chunked) {
				$this->write("0\r\n\r\n");
			}

			if (
				(!$this->pool->config->keepalive->value) 
				|| (!isset($req->attrs->server['HTTP_CONNECTION'])) 
				|| ($req->attrs->server['HTTP_CONNECTION'] !== 'keep-alive')
			) {
				$this->finish();
			}
		}
		$this->freeRequest($req);
	}
	public function freeRequest($req) {
		$this->req = null;
		setTimeout(array($this, 'onReadTimer'), 0);
	}
	public function onReadTimer($timer) {
		$this->onRead();
		$timer->free();
	}
	public function badRequest($req) {
		$this->write('400 Bad Request\r\n\r\n<html><head><title>400 Bad Request</title></head><body bgcolor="white"><center><h1>400 Bad Request</h1></center></body></html>');
		$this->finish();
	}
}

