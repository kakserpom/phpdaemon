<?php

/**
 * @package NetworkServers
 * @subpackage Base
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

class HTTPServerConnection extends Connection {
	protected $initialLowMark = 1;
	protected $initialHighMark = 8192;  // initial value of the maximum amout of bytes in buffer
	public $timeout = 45;

	public $req;
	
	const STATE_FIRSTLINE = 1;
	const STATE_HEADERS = 2;
	const STATE_CONTENT = 3;
	const STATE_PROCESSING = 4;
		
	public $sendfileCap = true; // we can use sendfile() with this kind of connection
	public $chunkedEncCap = true;

	public $EOL = "\r\n";

	public $currentHeader;
	public $copyout = null;
	public function init() {
		$this->ctime = microtime(true);
	}
	public function httpReadFirstline() {
		if (($l = $this->readline()) === null) {
			return false;
		}
		$e = explode(' ', $l);
		$u = isset($e[1]) ? parse_url($e[1]) : false;
		if ($u === false) {
			$this->badRequest($this->req);
			return false;
		}
		if (!isset($u['path'])) {
			$u['path'] = null;
		}
		$this->req->attrs->server['REQUEST_METHOD'] = $e[0];
		$this->req->attrs->server['REQUEST_TIME'] = time();
		$this->req->attrs->server['REQUEST_TIME_FLOAT'] = microtime(true);
		$this->req->attrs->server['REQUEST_URI'] = $u['path'] . (isset($u['query']) ? '?' . $u['query'] : '');
		$this->req->attrs->server['DOCUMENT_URI'] = $u['path'];
		$this->req->attrs->server['PHP_SELF'] = $u['path'];
		$this->req->attrs->server['QUERY_STRING'] = isset($u['query']) ? $u['query'] : null;
		$this->req->attrs->server['SCRIPT_NAME'] = $this->req->attrs->server['DOCUMENT_URI'] = isset($u['path']) ? $u['path'] : '/';
		$this->req->attrs->server['SERVER_PROTOCOL'] = isset($e[2]) ? $e[2] : 'HTTP/1.1';
		$this->req->attrs->server['REMOTE_ADDR'] = $this->addr;
		$this->req->attrs->server['REMOTE_PORT'] = $this->port;
		return true;
	}

	public function httpReadHeaders() {
		while (($l = $this->readLine()) !== null) {
			if ($l === '') {
				return true;
			}
			$e = explode(': ', $l);
			if (isset($e[1])) {
				$this->currentHeader = 'HTTP_' . strtoupper(strtr($e[0], HTTPRequest::$htr));
				$this->req->attrs->server[$this->currentHeader] = $e[1];
			}
			elseif (($e[0][0] === "\t" || $e[0][0] === "\x20") && $this->currentHeader) {
				 // multiline header continued
					$this->req->attrs->server[$this->currentHeader] .= $e[0];
			}
			else {
				// whatever client speaks is not HTTP anymore
				$this->badRequest($this->req);
				return false;
			}
		}
	}

	public function newRequest() {
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
		return $req;
	}

	public function httpProcessHeaders() {
		if (!isset($this->req->attrs->server['HTTP_CONTENT_LENGTH'])) {
			$this->req->attrs->server['HTTP_CONTENT_LENGTH'] = 0;
		}
		if (isset($u['host'])) {
			$this->req->attrs->server['HTTP_HOST'] = $u['host'];	
		}
		$this->req->attrs->params_done = true;
		if (
			isset($this->req->attrs->server['HTTP_CONNECTION']) && preg_match('~(?:^|\W)Upgrade(?:\W|$)~i', $this->req->attrs->server['HTTP_CONNECTION'])
			&& isset($this->req->attrs->server['HTTP_UPGRADE']) && (strtolower($this->req->attrs->server['HTTP_UPGRADE']) === 'websocket')
		) {
			if ($this->pool->WS) {
				$this->pool->WS->inheritFromRequest($this->req, $this);
			}
			return false;
		}

		$this->req = Daemon::$appResolver->getRequest($this->req, $this, isset($this->pool->config->responder->value) ? $this->pool->config->responder->value : null);
		$this->req = $this->req;
			
		if ($this->req instanceof stdClass) {
			$this->endRequest($this->req, 0, 0);
			return false;
		} else {
			if ($this->pool->config->sendfile->value && (!$this->pool->config->sendfileonlybycommand->value	|| isset($this->req->attrs->server['USE_SENDFILE'])) 
				&& !isset($this->req->attrs->server['DONT_USE_SENDFILE'])
			) {
				$fn = FS::tempnam($this->pool->config->sendfiledir->value, $this->pool->config->sendfileprefix->value);
				$req = $this->req;
				FS::open($fn, 'wb', function ($file) use ($req) {
					$req->sendfp = $file;
				});
				$this->req->header('X-Sendfile: ' . $fn);
			}
		}
		return true;
	}

	/**
	 * Called when new data received.
	 * @return void
	 */
	
	public function onRead() {
		$this->bev->input->copyout($this->copyout, 1024);
		start:
		if ($this->finished) {
			return;
		}
		if ($this->state === self::STATE_ROOT) {
			if ($this->req !== null) { // we have to wait the current request.
				return;
			}
			/*if (($d = $this->drainIfMatch("<policy-file-request/>\x00")) === null) { // partially match
				return;
			}
			if ($d) {
				if (($FP = FlashPolicyServer::getInstance($this->pool->config->fpsname->value, false)) && $FP->policyData) {
					$this->write($FP->policyData . "\x00");
				}
				$this->finish();
				return;
			}*/
			if (!$this->req = $this->newRequest()) {
				$this->finish();
				return;
			}
			$this->state = self::STATE_FIRSTLINE;

		} else {
			if (!$this->req || $this->state === self::STATE_PROCESSING) {
				if (isset($this->bev) && ($this->bev->input->length > 0)) {
					$eventMsg = 'Unexpected input (HTTP request, '.$this->state.'): '.json_encode($this->read($this->bev->input->length));
				}
				return;
			}
		}

		if ($this->state === self::STATE_FIRSTLINE) {
			if (!$this->httpReadFirstline()) {
				return;
			}
			$this->state = self::STATE_HEADERS;
		}		

		if ($this->state === self::STATE_HEADERS) {
			if (!$this->httpReadHeaders()) {
				return;
			}
			if (!$this->httpProcessHeaders()) {
				$this->finish();
				return;
			}
			$this->state = self::STATE_CONTENT;
		}
		if ($this->state === self::STATE_CONTENT) {
			$this->req->stdin($this->read($this->req->attrs->server['HTTP_CONTENT_LENGTH'] - $this->req->attrs->stdinlen));
			if (!$this->req->attrs->stdin_done) {
				return;
			}
			$this->state = self::STATE_PROCESSING;
			$this->freezeInput();
			if ($this->req->attrs->stdin_done && $this->req->attrs->params_done) {
				if ($this->pool->variablesOrder === null) {
					$this->req->attrs->request = $this->req->attrs->get + $this->req->attrs->post + $this->req->attrs->cookie;
				} else {
					for ($i = 0, $s = strlen($this->pool->variablesOrder); $i < $s; ++$i) {
						$char = $this->pool->variablesOrder[$i];
						if ($char == 'G') {
							$this->req->attrs->request += $this->req->attrs->get;
						}
						elseif ($char == 'P') {
							$this->req->attrs->request += $this->req->attrs->post;
						}
						elseif ($char == 'C') {
							$this->req->attrs->request += $this->req->attrs->cookie;
						}
					}
				}
				Daemon::$process->timeLastReq = time();
			}
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
			else {
				$this->finish();
			}
		}
		$this->freeRequest($req);
	}
	public function freeRequest($req) {
		if ($this->req === null || $this->req !== $req) {
			return;
		}
		$this->req = null;
		$this->state = self::STATE_ROOT;
		$this->unfreezeInput();
	}
	public function badRequest($req) {
		$this->state = self::STATE_ROOT;
		$this->write("400 Bad Request\r\n\r\n<html><head><title>400 Bad Request</title></head><body bgcolor=\"white\"><center><h1>400 Bad Request</h1></center></body></html>");
		$this->finish();
	}
}
