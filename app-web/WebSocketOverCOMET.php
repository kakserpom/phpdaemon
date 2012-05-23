<?php

/**
 * @package Applications
 * @subpackage WebSocketOverCOMET
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class WebSocketOverCOMET extends AsyncServer {

	const IPCPacketType_C2S = 0x01;
	const IPCPacketType_S2C = 0x02;
	const IPCPacketType_POLL = 0x03;

	public $IpcTransSessions = array();
	public $WS;
	public $ipcId;

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// IPC file path
			'ipcpath' => '/tmp/WsOverComet-%s.sock',
			// disabled by default
			'enable'     => 0
		);
	}

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if ($this->config->enable->value) {
			$this->WS = WebSocketServer::getInstance();
		}
	}

	/**
	 * Called when application instance is going to shutdown.
	 * @return boolean Ready to shutdown?
	 */
	public function onShutdown() {
		$path = sprintf($this->config->ipcpath->value, $this->ipcId);

		if (file_exists($path)) {
			unlink($path);
		}

		return TRUE;
	}
 
	/**
	 * Called when the worker is ready to go.
	 * @return void
	 */
	public function onReady() {
		if ($this->config->enable->value) {   
			$this->ipcId = sprintf('%x', crc32(Daemon::$process->pid . '-' . microtime(TRUE)));
			$this->bindSockets('unix:' . sprintf($this->config->ipcpath->value, $this->ipcId), 0, FALSE);
			$this->enableSocketEvents();
		}
	}

	/**
	 * Establish connection with the given application instance of WebSocketOverCOMET.
	 * @param string ID
	 * @return integer connId
	 */
	public function connectIPC($id) {
		if (isset($this->IpcTransSessions[$id])) {
			return $this->IpcTransSessions[$id];
		}

		$connId = $this->connectTo('unix:' . sprintf($this->config->ipcpath->value, basename($id)));

		if (!$connId) {
			return FALSE;
		}

		$this->sessions[$connId] = new WebSocketOverCOMET_IPCSession($connId, $this);
		$this->sessions[$connId]->ipcId = $id;

		return $this->IpcTransSessions[$id] = $connId;
	}

	/**
	 * Called when new connection is accepted
	 * @param integer Connection's ID
	 * @param string Address of the connected peer
	 * @return void
	 */
	protected function onAccepted($connId, $addr) {
		$this->sessions[$connId] = new WebSocketOverCOMET_IPCSession($connId, $this);
	}

	/**
	 * Creates Request.
	 * @param object Request.
	 * @param object Upstream application instance.
	 * @return object Request.
	 */
	public function beginRequest($req, $upstream) {
		if (!$this->config->enable->value) {
			return $req;
		}

		return new WebSocketOverCOMET_Request($this, $upstream, $req);
	}
	
}

class WebSocketOverCOMET_IPCSession extends SocketSession {

	public $ipcId;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() { }

	/**
	 * Called when new data received.
	 * @param string New data.
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;

		start:
			$l = strlen($this->buf);

			if ($l < 6) {
				// not enough data yet
				return;
			}

			extract(unpack('Ctype/Chlen/Nblen', binarySubstr($this->buf, 0, 6)));
  
			if ($l < 6 + $hlen + $blen) {
				// not enough data yet
				return;
			}

			$header = binarySubstr($this->buf, 6, $hlen);
			$body = binarySubstr($this->buf, 6 + $hlen, $blen);
			$this->buf = binarySubstr($this->buf, 6 + $hlen + $blen);
			list($reqId, $authKey) = explode('.', $header);

			if ($type === WebSocketOverCOMET::IPCPacketType_S2C) {
				if (isset($this->appInstance->polling[$header])) {
					foreach ($this->appInstance->polling[$header] as $pollReqId) {
						if (isset($this->appInstance->queue[$pollReqId])) {
							$req = $this->appInstance->queue[$pollReqId];

							if (isset($req->attrs->get['_script'])) {
								$q = Request::getString($req->attrs->get['q']);
								$body = 'Response' . $q . ' = ' . $body . ";\n";
							} else {
								$body .= "\n";
							}

							$req->out($body);
							$req->finish();
						}
					}
				}
			}
			elseif (
				isset($this->appInstance->queue[$reqId]->downstream) 
				&& $this->appInstance->queue[$reqId]->authKey == $authKey
			) {
				if ($type === WebSocketOverCOMET::IPCPacketType_C2S) {
					$this->appInstance->queue[$reqId]->downstream->onFrame($body, WebSocketServer::STRING);
					$this->appInstance->queue[$reqId]->atime = time();
				}
				elseif ($type === WebSocketOverCOMET::IPCPacketType_POLL) {
					list ($ts, $instanceId) = explode('|', $body);
					$this->appInstance->queue[$reqId]->polling[] = $instanceId;
					$this->appInstance->queue[$reqId]->flushBufferedPackets($ts);
					$this->appInstance->queue[$reqId]->atime = time();
				}
			} else {
				if (Daemon::$config->logerrors->value) {
					Daemon::log('[WORKER with ipcId = ' . $this->appInstance->ipcId 
						. '] Undispatched packet (type = ' . $type . ', reqId = ' . $reqId 
						. ', authKey = ' . $authKey . ', exists = ' 
						. (isset($this->appInstance->queue[$reqId]) ? '1 - ' 
							. get_class($this->appInstance->queue[$reqId]) : '0')
						. ').');
				}
			}

		goto start;
	}

	/**
	 * Called when the session finished.
	 * @return void
	 */
	public function onFinish() {
		unset($this->appInstance->sessions[$this->connId]);

		if (isset($this->ipcId)) {
			unset($this->appInstance->IpcTransSessions[$this->ipcId]);
		}
	}
	
}

class WebSocketOverCOMET_Request extends HTTPRequest {

	public $inited = FALSE;
	public $authKey;
	public $downstream;
	public $callbacks = array();
	public $polling = array();
	public $bufferedPackets = array();
	public $type;
	public $atime;
	public $connId;

	/**
	 * Constructor.
	 * @return void
	 */
	public function init() {
		if (isset($this->attrs->get['_pull'])) {
			$this->type = 'pull';
		}
		elseif (
			isset($this->attrs->get['_poll']) 
			&& isset($this->attrs->get['_init'])
		) {
			$this->type = 'pollInit';
		}
		elseif (isset($this->attrs->get['_poll'])) {
			$this->type = 'poll';
		} else {
			$this->type = 'push';
		}

		$this->server = &$this->attrs->server;
		$this->connId = $this->attrs->connId;

		$this->header('Cache-Control: no-cache, must-revalidate');
		$this->header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
	}

	/**
	 * Called when request iterated.
	 * @return integer Status.
	 */
	public function run() {
		if ($this->type === 'push') {
			$ret = array();
			$e = explode('.', self::getString($_REQUEST['_id']), 2);

			if (sizeof($e) != 2) {
				$ret['error'] = 'Bad cookie.';
			}
			elseif (
				!isset($_REQUEST['data']) 
				|| !is_string($_REQUEST['data'])
			) {
				$ret['error'] = 'No data.';
			}
			elseif ($connId = $this->appInstance->connectIPC(basename($e[0]))) {
				$this->appInstance->sessions[$connId]->write(
					pack('CCN', WebSocketOverCOMET::IPCPacketType_C2S, strlen($e[1]), strlen($_REQUEST['data']))
					. $e[1]
				);

				$this->appInstance->sessions[$connId]->write($_REQUEST['data']);
			} else {
				$ret['error'] = 'IPC error.';
			}

			echo json_encode($ret);

			return;
		} 
		elseif ($this->type === 'pull') {
			if (!$this->inited) {
				$this->authKey = sprintf('%x', crc32(microtime() . "\x00" . $this->attrs->server['REMOTE_ADDR']));
				$this->header('Content-Type: text/html; charset=utf-8');
				$this->inited = TRUE;
				$this->out('<!--' . str_repeat('-', 1024) . '->'); // Padding
				$this->out('<script type="text/javascript"> WebSocket.onopen("' . $this->appInstance->ipcId 
					. '.' . $this->idAppQueue . '.' . $this->authKey 
					. '"); </script>'."\n"
				);

				$appName = self::getString($_REQUEST['_route']);

				if (!isset($this->appInstance->WS->routes[$appName])) {
					if (
						isset(Daemon::$config->logerrors->value) 
						&& Daemon::$config->logerrors->value
					) {
						Daemon::log(__METHOD__ . ': undefined route \'' . $appName . '\'.');
					}

					return;
				}

				if (!$this->downstream = call_user_func($this->appInstance->WS->routes[$appName], $this)) {
					return;
				}
			}

			$this->sleep(1);
		}
		elseif ($this->type === 'pollInit') {
			if (!$this->inited) {
				$this->authKey = sprintf('%x', crc32(microtime() . "\x00" . $this->attrs->server['REMOTE_ADDR']));
				$this->header('Content-Type: application/x-javascript; charset=utf-8');
				$this->inited = TRUE;
				$appName = self::getString($_REQUEST['_route']);

				if (!isset($this->appInstance->WS->routes[$appName])) {
					if (
						isset(Daemon::$config->logerrors) 
						&& Daemon::$config->logerrors
					) {
						Daemon::log(__METHOD__ . ': undefined route \'' . $appName . '\'.');
					}

					echo json_encode(array('error' => 404));

					return;
				}

				if (!$this->downstream = call_user_func($this->appInstance->WS->routes[$appName], $this)) {
					echo json_encode(array('error' => 403));
					return;
				}

				$id = $this->appInstance->ipcId . '.' . $this->idAppQueue . '.' . $this->authKey;

				if (isset($_REQUEST['_script'])) {
					$q = self::getString($_GET['q']);

					if (ctype_digit($q)) {
						$this->out('Response' . $q . ' = ' . json_encode(array('id' => $id)) . ";\n");
					}
				} else {
					echo json_encode(array('id' => $id));
				}

				$this->atime = time();

				$this->finish(0, TRUE);
			}

			if ($this->atime < time() - 30) {
				if (isset($this->downstream)) {
					$this->downstream->onFinish();
					unset($this->downstream);
				}

				return 1;
			}

			$this->sleep(2);
		}
		elseif ($this->type === 'poll') {
			if (!$this->inited) {
				$this->header('Content-Type: text/plain; charset=utf-8');
				$this->inited = TRUE;

				$ret = array();

				$e = explode('.', self::getString($_REQUEST['_id']), 2);

				if (sizeof($e) != 2) {
					$ret['error'] = 'Bad cookie.';
				}
				elseif ($connId = $this->appInstance->connectIPC(basename($e[0]))) {
					$body = self::getString($_REQUEST['ts']) . '|' . $this->appInstance->ipcId;
					$this->appInstance->sessions[$connId]->write(
						pack('CCN', WebSocketOverCOMET::IPCPacketType_POLL, strlen($e[1]), strlen($body))
						. $e[1].$body
					);
				} else {
					$ret['error'] = 'IPC error.';
				}

				if (isset($req->attrs->get['_script'])) {
					$q = self::getString($req->attrs->get['q']);
	
					if (!ctype_digit($q)) {
						$ret['error'] = 'Bad q.';
					}
				}

				if (sizeof($ret)) {
					echo json_encode($ret);
					return;
				}

				$this->reqIdAuthKey = $e[1];
				$a = &$this->appInstance->polling[$this->reqIdAuthKey];

				if (!isset($a)) {
					$a = array();
				}

				$a[] = $this->idAppQueue;

				unset($a);

				$this->out("\n");
				$this->sleep(15);
			}

			return;
		}
	}

	/**
	 * Called when the request aborted.
	 * @return void
	 */
	public function onAbort() {
		if ($this->type !== 'pollInit') {
			if (isset($this->downstream)) {
				$this->downstream->onFinish();
				unset($this->downstream);
			}

			$this->finish();
		}
	}

	/**
	 * Called when the connection is ready to accept new data.
	 * @return void
	 */
	public function onWrite() {
		if ($this->type !== 'pollInit') {
			for ($i = 0, $s = sizeof($this->callbacks); $i < $s; ++$i) {
				call_user_func(array_shift($this->callbacks), $this);
			}

			if (is_callable(array($this->downstream, 'onWrite'))) {
				$this->downstream->onWrite();
			}
		}
	}

	public function compareFloats($a, $b, $precision = 3) {
		$k = pow(10, $precision);
		$a = round($a * $k) / $k;
		$b = round($b * $k) / $k;
		$cmp = strnatcmp((string) $a, (string) $b);

		return $cmp;
	}

	/**
	 * Flushes buffered packets (only for the long-polling method)	
	 * @param string Optional. Last timestamp.
	 * @return void
	 */
	public function flushBufferedPackets($ts = NULL) {
		if (
			!sizeof($this->polling)
			|| !sizeof($this->bufferedPackets)
		) {
			return;
		}

		$h = $this->idAppQueue . '.' . $this->authKey;

		if ($ts !== NULL) {
			$ts = (float) $ts;

			for ($i = sizeof($this->bufferedPackets) - 1; $i >= 0; --$i) {
				if ($this->compareFloats($this->bufferedPackets[$i][2], $ts) <= 0) {
					$this->bufferedPackets = array_slice($this->bufferedPackets, $i + 1);
					break;
				}
			}
		}

		if (!sizeof($this->bufferedPackets)) {
			return;
		}

		$packet = json_encode(array(
			'ts' => microtime(TRUE), 
			'packets' => $this->bufferedPackets
		));

		$packet = pack(
			'CCN',
			WebSocketOverCOMET::IPCPacketType_S2C,
			strlen($h),
			strlen($packet)
		) . $h . $packet;

		foreach ($this->polling as $k => $instanceId) {
			if ($connId = $this->appInstance->connectIPC(basename($instanceId))) {
				$this->appInstance->sessions[$connId]->write($packet);
			}

			unset($this->polling[$k]);
		}

		for ($i = 0, $s = sizeof($this->callbacks); $i < $s; ++$i) {
			call_user_func(array_shift($this->callbacks), $this);
		}

		if (is_callable(array($this->downstream, 'onWrite'))) {
			$this->downstream->onWrite();
		}
	}

	/**
	 * Sends a frame.
	 * @param string Frame's data.
	 * @param integer Frame's type. See the constants.
	 * @param callback Optional. Callback called when the frame is received by client.
	 * @return boolean Success.
	 */
	public function sendFrame($data, $type = 0x00, $callback = NULL) {
		if ($this->type === 'pollInit') {
			$this->bufferedPackets[] = array($type, $data, microtime(TRUE));
			$this->flushBufferedPackets();
		}
		else {
			$this->out('<script type="text/javascript">WebSocket.onmessage(' . json_encode($data) . ");</script>\n");
		}

		if ($callback) {
			$this->callbacks[] = $callback;
		}

		return TRUE;
	}

	/**
	 * Called when the request finished.
	 * @return void
	 */
	public function onFinish() {
		unset($this->appInstance->clients[$this->idAppQueue]);
	}
	
}
