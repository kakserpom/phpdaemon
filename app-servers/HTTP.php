<?php

/**
 * @package Applications
 * @subpackage HTTP
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class HTTP extends AsyncServer {

	protected $initialLowMark  = 1;         // initial value of the minimal amout of bytes in buffer
	protected $initialHighMark = 0xFFFFFF;  // initial value of the maximum amout of bytes in buffer
	protected $queuedReads = true;
	public $WS;
	private $variablesOrder;
	
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

//			'responder' => default app
		);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->config->enable->value) {
			if (
				($order = ini_get('request_order')) 
				|| ($order = ini_get('variables_order'))
			) {
				$this->variablesOrder = $order;
			} else {
				$this->variablesOrder = null;
			}

			$this->bindSockets(
				$this->config->listen->value,
				$this->config->listenport->value
			);
		}
	}

	/**
	 * Called when the worker is ready to go.
	 * @return void
	*/
	public function onReady() {
		if ($this->config->enable->value) {
			$this->WS = Daemon::$appResolver->getInstanceByAppName('WebSocketServer');
			$this->enableSocketEvents();
		}
	}
	
	/**
	 * Called when remote host is trying to establish the connection.
	 * @return boolean If true then we can accept new connections, else we can't.
	 */
	public function checkAccept($stream, $events, $arg) {
		if (Daemon::$process->reload) {
			return false;
		}
		
		return Daemon::$config->maxconcurrentrequestsperworker->value >= sizeof($this->queue);
	}
	
	/**
	 * Called when new connection is accepted
	 * @param integer Connection's ID
	 * @param string Address of the connected peer
	 * @return void
	 */
	protected function onAccepted($connId, $addr) {
		$this->poolState[$connId] = array(
			'n'     => 0,
			'state' => 0,
			'addr'  => $addr,
		);
	}

	/**
	 * Handles the output from downstream requests.
	 * @param object Request.
	 * @param string The output.
	 * @return void
	 */
	public function requestOut($r, $s) {
		$l = strlen($s);

		if (!isset(Daemon::$process->pool[$r->attrs->connId])) {
			return false;
		}

		Daemon::$process->writePoolState[$r->attrs->connId] = true;

		$w = event_buffer_write($this->buf[$r->attrs->connId], $s);

		if ($w === false) {
			$r->abort();

			return false;
		}
	}

	/**
	 * Handles the output from downstream requests.
	 * @return void
	 */
	public function endRequest($req, $appStatus, $protoStatus) {
		if (Daemon::$config->logevents->value) {
			Daemon::$process->log('endRequest(' . implode(',', func_get_args()) . ').');
		};

		if ($protoStatus === -1) {
			$this->closeConnection($req->attrs->connId);
		} else {
			if ($req->attrs->chunked) {
				Daemon::$process->writePoolState[$req->attrs->connId] = true;

				if (isset($this->buf[$req->attrs->connId])) {
					event_buffer_write($this->buf[$req->attrs->connId], "0\r\n\r\n");
				}
			}

			if (
				(!$this->config->keepalive->value) 
				|| (!isset($req->attrs->server['HTTP_CONNECTION'])) 
				|| ($req->attrs->server['HTTP_CONNECTION'] !== 'keep-alive')
			) {
				$this->finishConnection($req->attrs->connId);
			}
		}
	}
	public function badRequest($req) {
		$this->write($req->attrs->connId, '<html><head><title>400 Bad Request</title></head><body bgcolor="white"><center><h1>400 Bad Request</h1></center></body></html>');
		$this->finishConnection($req->attrs->connId);
	}

	/**
	 * Reads data from the connection's buffer.
	 * @param integer Connection's ID.
	 * @return void
	 */
	public function readConn($connId) {

		$buf = $this->read($connId, $this->readPacketSize);

		if (sizeof($this->poolState[$connId]) < 3) {
			return;
		}

		if ($this->poolState[$connId]['state'] === 0) {

			if (Daemon::$appResolver->checkAppEnabled('FlashPolicy'))
			if (strpos($buf, '<policy-file-request/>') !== false) {
				if (
					($FP = Daemon::$appResolver->getInstanceByAppName('FlashPolicy')) 
					&& $FP->policyData
				) {
					Daemon::$process->writePoolState[$connId] = true;
					event_buffer_write($this->buf[$connId], $FP->policyData . "\x00");
				}

				$this->finishConnection($connId);

				return;
			}

			++$this->poolState[$connId]['n'];

			$rid = ++Daemon::$process->reqCounter;
			$this->poolState[$connId]['state'] = 1;

			$req = new stdClass();
			$req->attrs = new stdClass();
			$req->attrs->request = array();
			$req->attrs->get = array();
			$req->attrs->post = array();
			$req->attrs->cookie = array();
			$req->attrs->server = array();
			$req->attrs->files = array();
			$req->attrs->session = null;
			$req->attrs->connId = $connId;
			$req->attrs->id = $this->poolState[$connId]['n'];
			$req->attrs->params_done = false;
			$req->attrs->stdin_done = false;
			$req->attrs->stdinbuf = '';
			$req->attrs->stdinlen = 0;
			$req->attrs->inbuf = '';
			$req->attrs->chunked = false;
			
			$req->queueId = $rid;

			if ($this->config->logqueue->value) {
				Daemon::$process->log('new request queued.');
			}

			Daemon::$process->queue[$rid] = $req;

			$this->poolQueue[$connId][$req->attrs->id] = $req;
		} else {
			$rid = $this->poolQueue[$connId][$this->poolState[$connId]['n']]->queueId;

			if (isset(Daemon::$process->queue[$rid])) {
				$req = Daemon::$process->queue[$rid];
			} else {
				Daemon::log('Unexpected input. Request ID: ' . $rid . '.');
				return;
			}
		}

		if ($this->poolState[$connId]['state'] === 1) {
			$req->attrs->inbuf .= $buf;

			if (Daemon::$appResolver->checkAppEnabled('FlashPolicy'))
			if (strpos($req->attrs->inbuf, '<policy-file-request/>') !== false) {
				if (
					($FP = Daemon::$appResolver->getInstanceByAppName('FlashPolicy')) 
					&& $FP->policyData
				) {
					Daemon::$process->writePoolState[$req->attrs->connId] = true;
					event_buffer_write($this->buf[$req->attrs->connId], $FP->policyData . "\x00");
				}

				$this->finishConnection($req->attrs->connId);

				return;
			}

			$buf = '';

			if (($p = strpos($req->attrs->inbuf, "\r\n\r\n")) !== false) {
				$headers = binarySubstr($req->attrs->inbuf, 0, $p);
				$headersArray = explode("\r\n", $headers);
				$req->attrs->inbuf = binarySubstr($req->attrs->inbuf, $p + 4);
				$command = explode(' ', $headersArray[0]);
				$u = isset($command[1])?parse_url($command[1]):false;
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
				) = explode(':', $this->poolState[$connId]['addr']);

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
					$req = Daemon::$appResolver->getRequest($req, $this, isset($this->config->responder->value) ? $this->config->responder->value : null);
				}

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
						$fn = tempnam($this->config->sendfiledir->value, $this->config->sendfileprefix->value);
						$req->sendfp = fopen($fn, 'wb');
						$req->header('X-Sendfile: ' . $fn);
					}

					$req->stdin($req->attrs->inbuf);
					$req->attrs->inbuf = '';

					$this->poolQueue[$connId][$req->attrs->id] = $req;
					$this->poolState[$connId]['state'] = 2;
				}
			}
		}

		if ($this->poolState[$connId]['state'] === 2) {
			$req->stdin($buf);

			if (Daemon::$config->logevents->value) {
				Daemon::log('stdin_done = ' . ($req->attrs->stdin_done ? '1' : '0'));
			}

			if ($req->attrs->stdin_done) {
				$this->poolState[$req->attrs->connId]['state'] = 0;
			}
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
