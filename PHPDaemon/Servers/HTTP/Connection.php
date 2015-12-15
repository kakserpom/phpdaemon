<?php
namespace PHPDaemon\Servers\HTTP;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Core\Timer;
use PHPDaemon\FS\FileSystem;
use PHPDaemon\HTTPRequest\Generic;
use PHPDaemon\HTTPRequest\Input;
use PHPDaemon\Request\IRequestUpstream;

/**
 * @package    NetworkServers
 * @subpackage Base
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Connection extends \PHPDaemon\Network\Connection implements IRequestUpstream {

	protected $initialLowMark = 1;

	/**
	 * @var integer initial value of the maximum amout of bytes in buffer
	 */
	protected $initialHighMark = 8192;

	protected $timeout = 45;

	protected $req;

	protected $keepaliveTimer;

	protected $freedBeforeProcessing = false;

	/**
	 * @TODO DESCR
	 */
	const STATE_FIRSTLINE  = 1;
	/**
	 * @TODO DESCR
	 */
	const STATE_HEADERS    = 2;
	/**
	 * @TODO DESCR
	 */
	const STATE_CONTENT    = 3;
	/**
	 * @TODO DESCR
	 */
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
	 * @TODO
	 * @return integer
	 */
	public function getKeepaliveTimeout() {
		return $this->pool->config->keepalive->value;
	}

	/**
	 * Read first line of HTTP request
	 * @return boolean|null Success
	 */
	protected function httpReadFirstline() {
		//D($this->look(2048));
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
		$srv                       = & $this->req->attrs->server;
		$srv['REQUEST_METHOD']     = $e[0];
		$srv['REQUEST_TIME']       = time();
		$srv['REQUEST_TIME_FLOAT'] = microtime(true);
		$srv['REQUEST_URI']        = $u['path'] . (isset($u['query']) ? '?' . $u['query'] : '');
		$srv['DOCUMENT_URI']       = $u['path'];
		$srv['PHP_SELF']           = $u['path'];
		$srv['QUERY_STRING']       = isset($u['query']) ? $u['query'] : null;
		$srv['SCRIPT_NAME']        = $srv['DOCUMENT_URI'] = isset($u['path']) ? $u['path'] : '/';
		$srv['SERVER_PROTOCOL']    = isset($e[2]) ? $e[2] : 'HTTP/1.1';
		$srv['REMOTE_ADDR']        = $this->host;
		$srv['REMOTE_PORT']        = $this->port;
		$srv['HTTPS']              = $this->ssl ? 'on' : 'off';
		return true;
	}

	/**
	 * Read headers line-by-line
	 * @return boolean|null Success
	 */
	protected function httpReadHeaders() {
		while (($l = $this->readLine()) !== null) {
			if ($l === '') {
				return true;
			}
			$e = explode(': ', $l);
			if (isset($e[1])) {
				$this->currentHeader                            = 'HTTP_' . strtoupper(strtr($e[0], Generic::$htr));
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
	 * @return \stdClass
	 */
	protected function newRequest() {
		$req                     = new \stdClass;
		$req->attrs              = new \stdClass();
		$req->attrs->request     = [];
		$req->attrs->get         = [];
		$req->attrs->post        = [];
		$req->attrs->cookie      = [];
		$req->attrs->server      = [];
		$req->attrs->files       = [];
		$req->attrs->session     = null;
		$req->attrs->paramsDone  = false;
		$req->attrs->inputDone   = false;
		$req->attrs->input       = new Input();
		$req->attrs->inputReaded = 0;
		$req->attrs->chunked     = false;
		$req->upstream           = $this;
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
		if ($this->req instanceof \stdClass) {
			$this->endRequest($this->req, 0, 0);
			return false;
		}
		else {
			if ($this->pool->config->sendfile->value && (!$this->pool->config->sendfileonlybycommand->value || isset($this->req->attrs->server['USE_SENDFILE']))
					&& !isset($this->req->attrs->server['DONT_USE_SENDFILE'])
			) {
				$req = $this->req;
				FileSystem::tempnam($this->pool->config->sendfiledir->value, $this->pool->config->sendfileprefix->value, function ($fn) use ($req) {
					FileSystem::open($fn, 'wb', function ($file) use ($req) {
						$req->sendfp = $file;
					});
					$req->header('X-Sendfile: ' . $fn);
				});
			}
			$this->req->callInit();
		}
		return true;
	}

	/* Used for debugging protocol issues */
	/*public function readline() {
		$s = parent::readline();
		Daemon::log(Debug::json($s));
		return $s;
	}

	public function write($s) {
		Daemon::log(Debug::json($s));
		parent::write($s);
	}*

	/**
	 * Called when new data received.
	 * @return void
	 */

	/**
	 * onRead
	 * @return void
	 */
	protected function onRead() {
		if (!$this->policyReqNotFound) {
			$d = $this->drainIfMatch("<policy-file-request/>\x00");
			if ($d === null) { // partially match
				return;
			}
			if ($d) {
				if (($FP = \PHPDaemon\Servers\FlashPolicy\Pool::getInstance($this->pool->config->fpsname->value, false)) && $FP->policyData) {
					$this->write($FP->policyData . "\x00");
				}
				$this->finish();
				return;
			}
			else {
				$this->policyReqNotFound = true;
			}
		}
		start:
		if ($this->finished) {
			return;
		}
		if ($this->state === self::STATE_ROOT) {
			if ($this->req !== null) { // we have to wait the current request
				return;
			}
			if (!$this->req = $this->newRequest()) {
				$this->finish();
				return;
			}
			$this->state = self::STATE_FIRSTLINE;

		}
		else {
			if (!$this->req || $this->state === self::STATE_PROCESSING) {
				if (isset($this->bev) && ($this->bev->input->length > 0)) {
					Daemon::log('Unexpected input (HTTP request, ' . $this->state . '): ' . json_encode($this->read($this->bev->input->length)));
				}
				return;
			}
		}

		if ($this->state === self::STATE_FIRSTLINE) {
			if (!$this->httpReadFirstline()) {
				return;
			}
			Timer::remove($this->keepaliveTimer);
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
			if (!isset($this->req->attrs->input) || !$this->req->attrs->input) {
				$this->finish();
				return;
			}
			$this->req->attrs->input->readFromBuffer($this->bev->input);
			if (!$this->req->attrs->input->isEOF()) {
				return;
			}
			$this->state = self::STATE_PROCESSING;
			if ($this->freedBeforeProcessing) {
				$this->freeRequest($this->req);
				$this->freedBeforeProcessing = false;
				goto start;
			}
			$this->freezeInput();
			if ($this->req->attrs->inputDone && $this->req->attrs->paramsDone) {
				if ($this->pool->variablesOrder === null) {
					$this->req->attrs->request = $this->req->attrs->get + $this->req->attrs->post + $this->req->attrs->cookie;
				}
				else {
					for ($i = 0, $s = strlen($this->pool->variablesOrder); $i < $s; ++$i) {
						$char = $this->pool->variablesOrder[$i];
						if ($char === 'G') {
							if (is_array($this->req->attrs->get)) {
								$this->req->attrs->request += $this->req->attrs->get;
							}
						}
						elseif ($char === 'P') {
							if (is_array($this->req->attrs->post)) {
								$this->req->attrs->request += $this->req->attrs->post;
							}
						}
						elseif ($char === 'C') {
							if (is_array($this->req->attrs->cookie)) {
								$this->req->attrs->request += $this->req->attrs->cookie;
							}
						}
					}
				}
				Daemon::$process->timeLastActivity = time();
			}
		}
	}

	/**
	 * Handles the output from downstream requests.
	 * @param  object  $req \PHPDaemon\Request\Generic.
	 * @param  string  $s   The output.
	 * @return boolean      Success
	 */
	public function requestOut($req, $s) {
		if ($this->write($s) === false) {
			$req->abort();
			return false;
		}
		return true;
	}

	/**
	 * End request
	 * @return void
	 */
	public function endRequest($req, $appStatus, $protoStatus) {
		if ($protoStatus === -1) {
			$this->close();
		}
		else {
			if ($req->attrs->chunked) {
				$this->write("0\r\n\r\n");
			}

			if (isset($req->keepalive) && $req->keepalive && $this->pool->config->keepalive->value) {
				$this->keepaliveTimer = setTimeout(function($timer) {
					$this->finish();
				}, $this->pool->config->keepalive->value);
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
		if ($this->state !== self::STATE_PROCESSING) {
			$this->freedBeforeProcessing = true;
			return;
		}
		$req->attrs->input = null;
		$this->req   = null;
		$this->state = self::STATE_ROOT;
		$this->unfreezeInput();
	}

	/**
	 * Called when connection is finished
	 * @return void
	 */
	public function onFinish() {
		Timer::remove($this->keepaliveTimer);
		if ($this->req !== null && $this->req instanceof Generic) {
			if (!$this->req->isFinished()) {
				$this->req->abort();
			}
		}
		$this->req = null;
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
