<?php
class HTTP extends AsyncServer {

	public $initialLowMark  = 1;         // initial value of the minimal amout of bytes in buffer
	public $initialHighMark = 0xFFFFFF;  // initial value of the maximum amout of bytes in buffer
	public $queuedReads = TRUE;
	public $WS;

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		$this->defaultConfig(array(
			'listen' =>  'tcp://0.0.0.0',
			'listen-port' => 80,
			'log-events' => 0,
			'log-queue' => 0,
			'send-file' => 0,
			'send-file-dir' => '/dev/shm',
			'send-file-prefix' => 'http-',
			'send-file-onlybycommand' => 0,
			'expose' => 1,
			'keepalive' => new Daemon_ConfigEntryTime('0s'),
			'enable' => 0,
		));
		if ($this->config->enable->value) {
			Daemon::log(__CLASS__ . ' up.');

			$this->bindSockets(
				$this->config->listen->value,
				$this->config->listenport->value
			);
		}
	}

	/**
	 * @method onReady
	 * @description Called when the worker is ready to go.
	 * @return void
	*/
	public function onReady() {
		if ($this->config->enable->value) {
			$this->WS = Daemon::$appResolver->getInstanceByAppName('WebSocketServer');
			$this->enableSocketEvents();
		}
	}

	/**
	 * @method onAccepted
	 * @description Called when new connection is accepted.
	 * @param integer Connection's ID.
	 * @param string Address of the connected peer.
	 * @return void
	 */
	public function onAccepted($connId, $addr) {
		$this->poolState[$connId] = array(
			'n'     => 0,
			'state' => 0,
			'addr'  => $addr,
		);
	}

	/**
	 * @method requestOut
	 * @description Handles the output from downstream requests.
	 * @param object Request.
	 * @param string The output.
	 * @return void
	 */
	public function requestOut($r, $s) {
		$l = strlen($s);

		if (!isset(Daemon::$worker->pool[$r->attrs->connId])) {
			return FALSE;
		}

		Daemon::$worker->writePoolState[$r->attrs->connId] = TRUE;

		$w = event_buffer_write($this->buf[$r->attrs->connId], $s);

		if ($w === FALSE) {
			$r->abort();

			return FALSE;
		}
	}

	/**
	 * @method endRequest
	 * @description Handles the output from downstream requests.
	 * @return void
	 */
	public function endRequest($req, $appStatus, $protoStatus) {
		if (Daemon::$config->logevents->value) {
			Daemon::log('[WORKER ' . Daemon::$worker->pid . '] endRequest(' . implode(',', func_get_args()) . ').');
		};

		if ($protoStatus === -1) {
			$this->closeConnection($req->attrs->connId);
		} else {
			if ($req->attrs->chunked) {
				Daemon::$worker->writePoolState[$req->attrs->connId] = TRUE;

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

	/**
	 * @method readConn
	 * @description Reads data from the connection's buffer.
	 * @param integer Connection's ID.
	 * @return void
	 */
	public function readConn($connId) {
		static $roles = array(
			1 => 'FCGI_RESPONDER',
			2 => 'FCGI_AUTHORIZER',
			3 => 'FCGI_FILTER',
		);

		$buf = $this->read($connId, $this->readPacketSize);

		if (sizeof($this->poolState[$connId]) < 3) {
			return;
		}

		if ($this->poolState[$connId]['state'] === 0) {
			if (strpos($buf, '<policy-file-request/>') !== FALSE) {
				if (
					($FP = Daemon::$appResolver->getInstanceByAppName('FlashPolicy')) 
					&& $FP->policyData
				) {
					Daemon::$worker->writePoolState[$connId] = TRUE;
					event_buffer_write($this->buf[$connId], $FP->policyData . "\x00");
				}

				$this->finishConnection($connId);

				return;
			}

			++Daemon::$worker->queryCounter;
			++$this->poolState[$connId]['n'];

			$rid = $connId . '-' . $this->poolState[$connId]['n'];
			$this->poolState[$connId]['state'] = 1;

			$req = new stdClass();
			$req->attrs = new stdClass();
			$req->attrs->request = array();
			$req->attrs->get = array();
			$req->attrs->post = array();
			$req->attrs->cookie = array();
			$req->attrs->server = array();
			$req->attrs->files = array();
			$req->attrs->session = NULL;
			$req->attrs->connId = $connId;
			$req->attrs->id = $this->poolState[$connId]['n'];
			$req->attrs->params_done = FALSE;
			$req->attrs->stdin_done = FALSE;
			$req->attrs->stdinbuf = '';
			$req->attrs->stdinlen = 0;
			$req->attrs->inbuf = '';
			$req->attrs->chunked = FALSE;

			if ($this->config->logqueue->value) {
				Daemon::log('[WORKER ' . Daemon::$worker->pid . '] new request queued.');
			}

			Daemon::$worker->queue[$rid] = $req;

			$this->poolQueue[$connId][$req->attrs->id] = $req;
		} else {
			$rid = $connId . '-' . $this->poolState[$connId]['n'];

			if (isset(Daemon::$worker->queue[$rid])) {
				$req = Daemon::$worker->queue[$rid];
			} else {
				Daemon::log('Unexpected input. Request ID: ' . $rid . '.');
				return;
			}
		}

		if ($this->poolState[$connId]['state'] === 1) {
			$req->attrs->inbuf .= $buf;

			if (strpos($req->attrs->inbuf, '<policy-file-request/>') !== FALSE) {
				if (
					($FP = Daemon::$appResolver->getInstanceByAppName('FlashPolicy')) 
					&& $FP->policyData
				) {
					Daemon::$worker->writePoolState[$req->attrs->connId] = TRUE;
					event_buffer_write($this->buf[$req->attrs->connId], $FP->policyData . "\x00");
				}

				$this->finishConnection($req->attrs->connId);

				return;
			}

			$buf = '';

			if (($p = strpos($req->attrs->inbuf, "\r\n\r\n")) !== FALSE) {
				$headers = binarySubstr($req->attrs->inbuf, 0, $p);
				$h = explode("\r\n", $headers);
				$req->attrs->inbuf = binarySubstr($req->attrs->inbuf, $p + 4);
				$e = explode(' ', $h[0]);
				$u = parse_url($e[1]);

				$req->attrs->server['REQUEST_METHOD'] = $e[0];
				$req->attrs->server['REQUEST_URI'] = $u['path'] . (isset($u['query']) ? '?' . $u['query'] : '');
				$req->attrs->server['DOCUMENT_URI'] = $u['path'];
				$req->attrs->server['PHP_SELF'] = $u['path'];
				$req->attrs->server['QUERY_STRING'] = isset($u['query']) ? $u['query'] : NULL;
				$req->attrs->server['SCRIPT_NAME'] = $req->attrs->server['DOCUMENT_URI'] = isset($u['path']) ? $u['path'] : '/';
				$req->attrs->server['SERVER_PROTOCOL'] = $e[2];

				list(
					$req->attrs->server['REMOTE_ADDR'],
					$req->attrs->server['REMOTE_PORT']
				) = explode(':', $this->poolState[$connId]['addr']);

				for ($i = 1, $n = sizeof($h); $i < $n; ++$i) {
					$e = explode(': ', $h[$i]);

					if (isset($e[1])) {
						$req->attrs->server['HTTP_' . strtoupper(strtr($e[0], Request::$htr))] = $e[1];
					}
				}

				$req->attrs->params_done = TRUE;

				if (
					isset($req->attrs->server['HTTP_CONNECTION']) 
					&& ($req->attrs->server['HTTP_CONNECTION'] === 'Upgrade')
					&& isset($req->attrs->server['HTTP_UPGRADE']) 
					&& ($req->attrs->server['HTTP_UPGRADE'] === 'WebSocket')
				) {
					if ($this->WS) {
						$this->WS->inheritFromRequest($req, $this);

						return;
					}
				} else {
					$req = Daemon::$appResolver->getRequest($req, $this, isset($this->config->responder->value) ? $this->config->responder->value : NULL);
				}

				if ($req instanceof stdClass) {
					$this->endRequest($req, 0, 0);
					unset(Daemon::$worker->queue[$rid]);
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

					Daemon::$worker->queue[$rid] = $req;

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
			if (
				($order = ini_get('request_order')) 
				|| ($order = ini_get('variables_order'))
			) {
				for ($i = 0, $s = strlen($order); $i < $s; ++$i) {
					$char = $order[$i];

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
			} else {
				$req->attrs->request = $req->attrs->get + $req->attrs->post + $req->attrs->cookie;
			}

			Daemon::$worker->timeLastReq = time();
		}
	}
}
