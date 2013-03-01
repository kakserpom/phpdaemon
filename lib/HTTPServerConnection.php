<?php

/**
 * @package NetworkServers
 * @subpackage Base
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

class HTTPServerConnection extends Connection {
	protected $initialHighMark = 1;
	protected $initialHighMark = 8192;  // initial value of the maximum amout of bytes in buffer
	public $timeout = 45;

	public $req;
	
	const STATE_FIRSTLINE = 1;
	const STATE_HEADERS = 2;
	const STATE_CONTENT = 3;

	
	public $sendfileCap = true; // we can use sendfile() with this kind of connection
	public $chunkedEncCap = true;

	public $EOL = "\r\n";

	/**
	 * Called when new data received.
	 * @return void
	 */
	
	public function onRead() {
		start:
		if ($this->finished) {
			return;
		}
		if ($this->state === self::STATE_ROOT) {
			if ($this->req !== null) { // we have to wait the current request.
				return;
			}
			if (($d = $this->drainIfMatch("<policy-file-request/>\x00")) === null) { // partially match
				return;
			}
			if ($d) {
				if (($FP = FlashPolicyServer::getInstance($this->pool->config->fpsname->value, false)) && $FP->policyData) {
					$this->write($FP->policyData . "\x00");
				}
				$this->finish();
				return;
			}
			$this->state = self::STATE_FIRSTLINE;
			$req = new stdClass;
			$req->attrs = new stdClass();
			$req->attrs->request = [];
			$req->attrs->get = [];
			$req->attrs->post = [];
			$req->attrs->cookie = [];
			$req->attrs->server = [];
			$req->attrs->files = [];
			$req->attrs->session = null;
			$req->attrs->params_done = false;
			$req->attrs->stdin_done = false;
			$req->attrs->stdinbuf = '';
			$req->attrs->stdinlen = 0;
			$req->attrs->chunked = false;
			$req->upstream = $this;
			$this->req = $req;

		} else {
			if (!$this->req) {
				if ($this->bev->input->length > 0) {
					Daemon::log('Unexpected input (HTTP request): '.json_encode($this->read($this->bev->input->length)));
				}
				return;
			}
			$req = $this->req;
		}

		if ($this->state === self::STATE_FIRSTLINE) {
			if (($l = $this->readline()) === null) {
				return;
			}
			$e = explode(' ', $l);
			$u = isset($e[1]) ? parse_url($e[1]) : false;
			if ($u === false) {
				$this->badRequest($req);
				return;
			}
			if (!isset($u['path'])) {
				$u['path'] = null;
			}
			$req->attrs->server['REQUEST_METHOD'] = $e[0];
			$req->attrs->server['REQUEST_TIME'] = time();
			$req->attrs->server['REQUEST_TIME_FLOAT'] = microtime(true);
			$req->attrs->server['REQUEST_URI'] = $u['path'] . (isset($u['query']) ? '?' . $u['query'] : '');
			$req->attrs->server['DOCUMENT_URI'] = $u['path'];
			$req->attrs->server['PHP_SELF'] = $u['path'];
			$req->attrs->server['QUERY_STRING'] = isset($u['query']) ? $u['query'] : null;
			$req->attrs->server['SCRIPT_NAME'] = $req->attrs->server['DOCUMENT_URI'] = isset($u['path']) ? $u['path'] : '/';
			$req->attrs->server['SERVER_PROTOCOL'] = isset($e[2]) ? $e[2] : 'HTTP/1.1';

			$req->attrs->server['REMOTE_ADDR'] = $this->addr;
			$req->attrs->server['REMOTE_PORT'] = $this->port;

			$this->state = self::STATE_HEADERS;
		}		

		if ($this->state === self::STATE_HEADERS) {
			while (($l = $this->readLine()) !== null) {
				if ($l === '') {
					goto processHeaders;
				}
				$e = explode(': ', $l);
				if (isset($e[1])) {
					$currentHeader = 'HTTP_' . strtoupper(strtr($e[0], HTTPRequest::$htr));
					$req->attrs->server[$currentHeader] = $e[1];
				}
				elseif ($e[0][0] === "\t" || $e[0][0] === "\x20") {
					 // multiline header continued
						$req->attrs->server[$currentHeader] .= $e[0];
				}
				else {
					// whatever client speaks is not HTTP anymore
					$this->badRequest($req);
					return;
				}
			}
			return;
			processHeaders:

			if (!isset($req->attrs->server['HTTP_CONTENT_LENGTH'])) {
				$req->attrs->server['HTTP_CONTENT_LENGTH'] = 0;
			}
			if (isset($u['host'])) {
				$req->attrs->server['HTTP_HOST'] = $u['host'];	
			}

			$req->attrs->params_done = true;
			if (isset($req->attrs->server['HTTP_CONNECTION']) && preg_match('~(?:^|\W)Upgrade(?:\W|$)~i', $req->attrs->server['HTTP_CONNECTION'])
			&& isset($req->attrs->server['HTTP_UPGRADE']) && (strtolower($req->attrs->server['HTTP_UPGRADE']) === 'websocket')) {
				if ($this->pool->WS) {
					$this->pool->WS->inheritFromRequest($req, $this->pool);
				} else {
					$this->finish();
				}
				return;
			}
			$req = Daemon::$appResolver->getRequest($req, $this, isset($this->pool->config->responder->value) ? $this->pool->config->responder->value : null);
			$this->req = $req;
			
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
		if ($this->state === self::STATE_CONTENT) {
			$req->stdin($this->read($req->attrs->server['HTTP_CONTENT_LENGTH'] - $req->attrs->stdinlen));
			if (!$req->attrs->stdin_done) {
				return;
			}
			$this->state = self::STATE_ROOT;
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
		$this->state = self::STATE_ROOT;
		$this->write("400 Bad Request\r\n\r\n<html><head><title>400 Bad Request</title></head><body bgcolor=\"white\"><center><h1>400 Bad Request</h1></center></body></html>");
		$this->finish();
	}
}
