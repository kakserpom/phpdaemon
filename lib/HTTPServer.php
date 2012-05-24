<?php

/**
 * @package NetworkServer
 * @subpackage HTTPServer
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class HTTPServer extends NetworkServer {

	public $variablesOrder;
	public $WS; // WebSocketServer instance

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// listen to
			'listen'     => 'tcp://0.0.0.0',
			// listen port
			'listenport' => 80,
			// log events
			'log-events' => 0,
			// log queue
			'log-queue' => 0,
			// @todo add description strings
			'send-file' => 0,
			'send-file-dir' => '/dev/shm',
			'send-file-prefix' => 'http-',
			'send-file-onlybycommand' => 0,
			// expose your soft by X-Powered-By string
			'expose' => 1,
			// @todo add description strings
			'keepalive' => new Daemon_ConfigEntryTime('0s'),
			'chunksize' => new Daemon_ConfigEntrySize('8k'),
			'defaultcharset' => 'utf-8',
			// disabled by default
			'enable'     => 0
			//'responder' => default app
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
	 * Called when the worker is ready to go.
	 * @return void
	*/
	public function onReady() {
		if ($this->config->enable->value) {
			$this->WS = WebSocketServer::getInstance();
		}
	}
	
	/**
	 * Handles the output from downstream requests.
	 * @param object Request.
	 * @param string The output.
	 * @return boolean Success.
	 */
	public function requestOut($req, $s) {
		
		$conn = $this->getConnectionById($req->attrs->connId);

		if (!$conn) {
			return false;
		}
		
		
		$l = strlen($s);

		$w = $conn->write($s);

		if ($w === false) {
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
		$conn = $this->getConnectionById($req->attrs->connId);

		if (!$conn) {
			return false;
		}

		if ($protoStatus === -1) {
			$conn->close();
		} else {
			if ($req->attrs->chunked) {
				if (isset($this->buf[$req->attrs->connId])) {
					$conn->write("0\r\n\r\n");
				}
			}

			if (
				(!$this->config->keepalive->value) 
				|| (!isset($req->attrs->server['HTTP_CONNECTION'])) 
				|| ($req->attrs->server['HTTP_CONNECTION'] !== 'keep-alive')
			) {
				$conn->finish();
			}
		}
	}
	public function badRequest($req) {
		$conn->write('<html><head><title>400 Bad Request</title></head><body bgcolor="white"><center><h1>400 Bad Request</h1></center></body></html>');
		$conn->finish();
	}

}

class HTTPServerConnection extends Connection {
	protected $initialLowMark  = 1;         // initial value of the minimal amout of bytes in buffer
	protected $initialHighMark = 0xFFFFFF;  // initial value of the maximum amout of bytes in buffer
	
	const STATE_HEADERS = 1;
	const STATE_CONTENT = 2;

	/**
	 * Called when new data received.
	 * @return void
	 */
	
	public function onRead() {
		start:
		$buf = $this->read($this->readPacketSize);

		if ($this->state === self::STATE_ROOT) {

			if (Daemon::$appResolver->checkAppEnabled('FlashPolicy'))
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

			$rid = ++Daemon::$process->reqCounter;

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
			$req->attrs->connId = $this->connId;
			$req->attrs->id = $rid;
			$req->attrs->params_done = false;
			$req->attrs->stdin_done = false;
			$req->attrs->stdinbuf = '';
			$req->attrs->stdinlen = 0;
			$req->attrs->inbuf = '';
			$req->attrs->chunked = false;
			$req->queueId = $rid;

			Daemon::$process->queue[$rid] = $req; // to deprecate
			$this->rid = $rid;

		} else {
			if (!isset(Daemon::$process->queue[$this->rid])) {
				Daemon::log('Unexpected input. Request #' . $this->rid . '.');
				return;
			}
			$req = Daemon::$process->queue[$this->rid];
		}

		if ($this->state === self::STATE_HEADERS) {
			$req->attrs->inbuf .= $buf;
			$buf = '';

			if (($p = strpos($req->attrs->inbuf, "\r\n\r\n")) !== false) {
				$headers = binarySubstr($req->attrs->inbuf, 0, $p);
				$headersArray = explode("\r\n", $headers);
				$req->attrs->inbuf = binarySubstr($req->attrs->inbuf, $p + 4);
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
				$req->attrs->server['SERVER_PROTOCOL'] = $command[2];

				list(
					$req->attrs->server['REMOTE_ADDR'],
					$req->attrs->server['REMOTE_PORT']
				) = explode(':', $this->addr);

				for ($i = 1, $n = sizeof($headersArray); $i < $n; ++$i) {
					$e = explode(': ', $headersArray[$i]);
					if (isset($e[1])) {
						$req->attrs->server['HTTP_' . strtoupper(strtr($e[0], HTTPRequest::$htr))] = $e[1];
					}
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
					if ($this->WS) {
						$this->WS->inheritFromRequest($req, $this);
						return;
					}
				} else {
					$req = Daemon::$appResolver->getRequest($req, $this->pool, isset($this->pool->config->responder->value) ? $this->pool->config->responder->value : null);
				}

				if ($req instanceof stdClass) {
					$this->endRequest($req, 0, 0);
					unset(Daemon::$process->queue[$rid]);
				} else {
					if ($this->pool->config->sendfile->value && (!$this->pool->config->sendfileonlybycommand->value	|| isset($req->attrs->server['USE_SENDFILE'])) 
						&& !isset($req->attrs->server['DONT_USE_SENDFILE'])
					) {
						$fn = tempnam($this->pool->config->sendfiledir->value, $this->pool->config->sendfileprefix->value);
						$req->sendfp = fopen($fn, 'wb');
						$req->header('X-Sendfile: ' . $fn);
					}
					$req->stdin($req->attrs->inbuf);
					$req->attrs->inbuf = '';
					$this->state = self::STATE_CONTENT;
				}
			}
			else {
				return; // not enough data
			}
		}
		if ($this->state === self::STATE_CONTENT) {
			$req->stdin($buf);
			if ($req->attrs->stdin_done) {
				$this->state = self::STATE_ROOT;
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
		else {
			goto start;
		}
	}
}

