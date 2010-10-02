<?php
class RTEP extends AsyncServer {

	public $sessions = array();     // Active sessions
	public $events = array();       // Current events
	public $eventGroups = array();  // Callbacks for groups of events.

	/**
	 * @method init
	 * @description Constructor.
	 * @return void
	 */
	public function init() {
		$this->defaultConfig(array(
			'listen'     => 'tcp://0.0.0.0',
			'listenport' => 844,
			'enable'     => 0,
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
	 * @method onAccepted
	 * @description Called when new connection is accepted.
	 * @param integer Connection's ID.
	 * @param string Address of the connected peer.
	 * @return void
	 */
	public function onAccepted($connId, $addr) {
		$this->sessions[$connId] = new RTEPSession($connId, $this);
		$this->sessions[$connId]->clientAddr = $addr;
	}

	/**
	 * @method onEvent
	 * @description Called when new packet received.
	 * @param array Packet.
	 * @return void
	 */
	public function onEvent($packet) {
		if (Daemon::$config->logevents->value) {
			Daemon::log(__METHOD__ . ': ' . Daemon::var_dump($packet));
		}

		$evName = isset($packet['event']['name']) ? (string) $packet['event']['name'] : '';

		$packet['event']['_ts'] = microtime(TRUE);
		$packet['event']['currentTime'] = microtime(TRUE); // hack

		if (isset($this->events[$evName])) {
			foreach ($this->events[$evName] as $connId => $v) {
				if (isset($this->sessions[$connId])) {
					$this->sessions[$connId]->send($packet);
				}
			}
		}
	}
}

class RTEPSession extends SocketSession {

	public $server = FALSE;           // Is this S2S-session?
	public $events = array();         // Events
	public $onEventRemove = array();  // HHash of callbacks called when events removed (evName -> callback).
	public $http = FALSE;             // Is this HTTP-connection?
	public $firstline = TRUE;
	public $pstate = 0;

	/**
	 * @method send
	 * @description Sends a packet.
	 * @param array Packet.
	 * @return void
	 */
	public function send($packet) {
		if (Daemon::$config->logevents->value) {
			Daemon::log(__METHOD__ . ' invoked (' . $this->clientAddr . '): ' . Daemon::var_dump($packet));
		}

		if ($this->http) {
			$s = json_encode($packet);
			$l = strlen($s);

			for ($o = 0; $o < $l;) {
				$c = min(Daemon::$config->chunksize->value, $l - $o);
				$chunk = dechex($c) . "\r\n"
					. ($c === $l ? $s : binarySubstr($s, $o, $c))
					. "\r\n";

				$this->write($chunk);

				$o += $c;
			}
		} else {
			$this->writeln(json_encode($packet));
		}
	}

	/**
	 * @method addEvent
	 * @description Adds event.
	 * @param string Event name.
	 * @return void
	 */
	public function addEvent($evName) {
		if (!isset($this->appInstance->events[$evName])) {
			$this->appInstance->events[$evName] = array();
		}

		$this->appInstance->events[$evName][$this->connId] = 0;

		if (!in_array($evName, $this->events)) {
			$this->events[] = $evName;
		}

		if (Daemon::$config->logevents->value) {
			Daemon::log('Event \'' . $evName . '\' added to client ' . $this->clientAddr . '.');
		}
	}

	/**
	 * @method removeEvent
	 * @description Removes event.
	 * @param string Event name.
	 * @return void
	 */
	public function removeEvent($evName) {
		if (($s = sizeof($this->appInstance->events[$evName])) === 1) {
			unset($this->appInstance->events[$evName]);
		} else {
			unset($this->appInstance->events[$evName][$this->connId]);
		}

		if (isset($this->onEventRemove[$evName])) {
			call_user_func($this->onEventRemove[$evName], $this);
		}

		--$s;

		if (Daemon::$config->logevents->value) {
			Daemon::log('Event \'' . $evName . '\' removed from client ' . $this->clientAddr . '.');
		}

		return $s;
	}

	/**
	 * @method onFinish
	 * @description Called when the session finished.
	 * @return void
	 */
	public function onFinish() {
		if (Daemon::$config->logevents->value) {
			Daemon::log(__METHOD__ . ' invoked');
		}

		foreach ($this->events as $evName) {
			$this->removeEvent($evName);
		}

		unset($this->appInstance->sessions[$this->connId]);
	}

	/**
	 * @method onRequest
	 * @description Called when new request received.
	 * @return void
	 */
	public function onRequest($packet) {
		if (Daemon::$config->logevents->value) {
			Daemon::log(__METHOD__ . ': ' . Daemon::var_dump($packet));
		}

		$request = (isset($packet['request']) && is_array($packet['request'])) ? $packet['request'] : array();
		$opstr = isset($request['op']) ? (string) $request['op'] : '';
		$e = explode('.', $opstr);
		$args = $e;
		$op = $e[0];

		$out = array(
			'id'       => isset($packet['id']) ? $packet['id'] : NULL,
			'response' => array('op' => $op),
			'type'     => 'response'
		);

		$response = &$out['response'];

		if ($op === 'ping') {
			$response['msg'] = 'pong';
		}
		elseif (isset($this->appInstance->operations[$op])) {
			call_user_func_array($this->appInstance->operations[$op], array($this, $packet, $args, &$out['response']));
		}
		elseif ($op === 'subscribeEvents') {
			if (
				(!isset($request['events'])) 
				|| (!is_array($request['events']))
			) {
				$response['status'] = -1;
			} else {
				foreach ($request['events'] as &$evName) {
					$args = explode(':', $evName);
					$evName = $args[0];

					if (isset($this->appInstance->eventGroups[$evName])) {
						call_user_func($this->appInstance->eventGroups[$evName][0], $this, $packet, $args);
					}
				}
			}

			$response['status'] = 1;
		}
		elseif ($op === 'unsubscribeEvents') {
			if (
				(!isset($request['events'])) 
				|| (!is_array($request['events']))
			) {
				$response['status'] = -1;
			} else {
				foreach ($request['events'] as &$evName) {
					$args = explode(':', $evName);
					$evName = $args[0];

					if (isset($this->appInstance->eventGroups[$evName])) {
						call_user_func($this->appInstance->eventGroups[$evName][1], $this, $packet, $args);
					}
				}
			}

			$response['status'] = 1;
		}
		elseif ($op === 'event') {
			$event = array(
				'type'  => 'event',
				'event' => $request['event'],
			);

			$this->appInstance->onEvent($event);
			$response['status'] = 1;
		} else {
			$reponse['error'] = 'Unrecognized operation.';
		}

		$this->send($out);
	}

	public function stdin($buf) {
		$this->buf .= $buf;

		while (($l = $this->gets()) !== FALSE) {
			if ($this->firstline) {
				$this->firstline = FALSE;
				$e = explode(' ', $l, 2);

				if (
					($e[0] === 'GET') 
					|| ($e[0] === 'POST') 
					|| ($e[0] === 'PUT')
				) {
					$this->http = TRUE;
				} else {
					goto performQuery;
				}
			}
			elseif (
				$this->http 
				&& ($this->pstate === 0)
			) {
				if (trim($l, "\r\n") === '') {
					$this->write("HTTP/1.1 200 OK\r\nConnection: close\r\nServer: RTEP server\r\n"
						. "X-Powered-By: phpDaemon\r\nContent-Type: text/json\r\n"
						. "Transfer-Encoding: chunked\r\n\r\n"
					);

					$this->pstate = 1;
				}
			} else {
				performQuery:
					$r = json_decode($l, TRUE);
					$this->onRequest($r);
			}
		}

		if (
			(strpos($this->buf, "\xff\xf4\xff\xfd\x06") !== FALSE) 
			|| (strpos($this->buf, "\xff\xec") !== FALSE)
		) {
			$this->finish();
		}
	}
}
