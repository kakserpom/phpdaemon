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
	protected $timeout = 45;

	protected $req;
	
	const STATE_FIRSTLINE = 1;
	const STATE_HEADERS = 2;
	const STATE_CONTENT = 3;
	const STATE_PROCESSING = 4;
		

	protected $EOL = "\r\n";
	protected $currentHeader;

	protected $policyReqNotFound = false;

	/**
	 * Check if Sendfile is supported here.
	 * @return boolean Succes
	 */
	public function checkSendfileCap() { // @DISCUSS
		return true;
	}

	/**
	 * Check if Chunked encoding is supported here.
	 * @return boolean Succes
	 */
	public function checkChunkedEncCap() { // @DISCUSS
		return true;
	}
	
	/**
	 * Constructor
	 * @return void
	 */
	protected function init() {
		$this->ctime = microtime(true);
	}

	/**
	 * Read first line of HTTP request
	 * @return boolean Success
	 * @return void
	 */
	protected function httpReadFirstline() {
		if (($l = $this->readline()) === null) {
			return null;
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
		if (isset($u['host'])) {
			$this->req->attrs->server['HTTP_HOST'] = $u['host'];	
		}
		$srv = &$this->req->attrs->server;
		$srv['REQUEST_METHOD'] = $e[0];
		$srv['REQUEST_TIME'] = time();
		$srv['REQUEST_TIME_FLOAT'] = microtime(true);
		$srv['REQUEST_URI'] = $u['path'] . (isset($u['query']) ? '?' . $u['query'] : '');
		$srv['DOCUMENT_URI'] = $u['path'];
		$srv['PHP_SELF'] = $u['path'];
		$srv['QUERY_STRING'] = isset($u['query']) ? $u['query'] : null;
		$srv['SCRIPT_NAME'] = $srv['DOCUMENT_URI'] = isset($u['path']) ? $u['path'] : '/';
		$srv['SERVER_PROTOCOL'] = isset($e[2]) ? $e[2] : 'HTTP/1.1';
		$srv['REMOTE_ADDR'] = $this->host;
		$srv['REMOTE_PORT'] = $this->port;
		return true;
	}

	/**
	 * Read headers line-by-line
	 * @return boolean Success
	 * @return void
	 */
	protected function httpReadHeaders() {
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
		return null;
	}


	/**
	 * Creates new Request object
	 * @return object
	 */
	protected function newRequest() {
		$req = new stdClass;
		$req->attrs = new stdClass();
		$req->attrs->request = [];
		$req->attrs->get = [];
		$req->attrs->post = [];
		$req->attrs->cookie = [];
		$req->attrs->server = [];
		$req->attrs->files = [];
		$req->attrs->session = null;
		$req->attrs->paramsDone = false;
		$req->attrs->inputDone = false;
		$req->attrs->input = new HTTPRequestInput;
		$req->attrs->inputReaded = 0;
		$req->attrs->chunked = false;
		$req->upstream = $this;
		return $req;
	}


	/**
	 * Process HTTP headers
	 * @return boolean Success
	 */
	protected function httpProcessHeaders() {
		$this->req->attrs->paramsDone = true;
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
	
	protected function onRead() {
		if (!$this->policyReqNotFound) {
			$d = $this->drainIfMatch("<policy-file-request/>\x00");
			if ($d === null) { // partially match
				return;
			}
			if ($d) {
				if (($FP = FlashPolicyServer::getInstance($this->pool->config->fpsname->value, false)) && $FP->policyData) {
					$this->write($FP->policyData . "\x00");
				}
				$this->finish();
				return;
			} else {
				$this->policyReqNotFound = true;
			}
		}
		start:
		if ($this->finished) {
			return;
		}
		if ($this->state === self::STATE_ROOT) {
			if ($this->req !== null) { // we have to wait the current request.
				return;
			}
			if (!$this->req = $this->newRequest()) {
				$this->finish();
				return;
			}
			$this->state = self::STATE_FIRSTLINE;

		} else {
			if (!$this->req || $this->state === self::STATE_PROCESSING) {
				if (isset($this->bev) && ($this->bev->input->length > 0)) {
					Daemon::log('Unexpected input (HTTP request, '.$this->state.'): '.json_encode($this->read($this->bev->input->length)));
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
			if (!$this->req->attrs->input) {
				return;
			}
			$this->req->attrs->input->readFromBuffer($this->bev->input);
			if (!$this->req || !$this->req->attrs->input->isEOF()) {
				return;
			}
			$this->state = self::STATE_PROCESSING;
			$this->freezeInput();
			if ($this->req->attrs->inputDone && $this->req->attrs->paramsDone) {
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
	 * @return boolean Success
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

	/**
	 * Frees this request
	 * @return void
	 */
	public function freeRequest($req) {
		if ($this->req === null || $this->req !== $req) {
			return;
		}
		$this->req = null;
		$this->state = self::STATE_ROOT;
		$this->unfreezeInput();
	}


	/**
	 * Called when connection is finished
	 * @return void
	 */
	public function onFinish() {
		if ($this->req !== null && $this->req instanceof Request) {
			if (!$this->req->isFinished()) {
				$this->req->abort();
			}
		}
	}

	/**
	 * Send Bad request
	 * @return void
	 */
	public function badRequest($req) {
		$this->state = self::STATE_ROOT;
		$this->write("400 Bad Request\r\n\r\n<html><head><title>400 Bad Request</title></head><body bgcolor=\"white\"><center><h1>400 Bad Request</h1></center></body></html>");
		$this->finish();
	}
}
