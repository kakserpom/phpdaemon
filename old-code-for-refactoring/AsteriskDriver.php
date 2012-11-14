<?php
/**
 * Driver for Asterisk Call Manager/1.1
 *
 * @package Applications
 * @subpackage AsteriskDriver
 * 
 * @version 1.0.1
 * @author Ponomarev Dmitry <ponomarev.base@gmail.com>
 */
class AsteriskDriver extends AsyncServer {
	
	const CONN_STATE_START                                  = 0;
	const CONN_STATE_GOT_INITIAL_PACKET                     = 0.1;
	const CONN_STATE_AUTH                                   = 1;
	const CONN_STATE_LOGIN_PACKET_SENT                      = 1.1;
	const CONN_STATE_CHALLENGE_PACKET_SENT                  = 1.2;
	const CONN_STATE_LOGIN_PACKET_SENT_AFTER_CHALLENGE      = 1.3;
	const CONN_STATE_HANDSHAKED_OK                          = 2.1;
	const CONN_STATE_HANDSHAKED_ERROR                       = 2.2;
	
	const INPUT_STATE_START         = 0;
	const INPUT_STATE_END_OF_PACKET = 1;
	const INPUT_STATE_PROCESSING    = 2;

	/**
	 * Active sessions.
	 * @var array
	 */
	public $sessions = array();
	
	/**
	 * Active connections.
	 * @var array
	 */
	public $servConn = array();
	
	/**
	 * Asterisk Call Manager Interface versions for each session.
	 * @var array
	 */
	public $amiVersions = array();

	/**
	 * Setting default config options
	 * Overriden from AppInstance::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			// default server
			'server' => 'tcp://127.0.0.1',
			// default port
			'port'   => 5038,
			// disabled by default
			'enable' => 0
		);
	}

	/**
	 * Establishes connection.
	 * @param string $addr Optional address.
	 * @return AsteriskDriverSession Session object.
	 */
	public function getConnection($addr = null) {
		if ($this->config->enable->value) {
			if (empty($addr)) {
				$addr = $this->config->server->value;
			}

			if (isset($this->servConn[$addr])) {
				foreach ($this->servConn[$addr] as &$c) {
					if (
						isset($this->sessions[$c])
						&& !sizeof($this->sessions[$c]->callbacks)
					) {
						return $this->sessions[$c];
					}
				}
			} else {
				$this->servConn[$addr] = array();
			}

			$u = parse_url($addr);

			if (!isset($u['port'])) {
				$u['port'] = $this->config->port->value;
			}

			$connId = $this->connectTo($u['host'], $u['port']);

			if (!$connId) {
				return;
			}

			$this->sessions[$connId] = new AsteriskDriverSession($connId, $this);
			$this->sessions[$connId]->addr = $addr;

			if (isset($u['user'])) {
				$this->sessions[$connId]->username = $u['user'];
			}

			if (isset($u['pass'])) {
				$this->sessions[$connId]->secret = $u['pass'];
			}

			$this->servConn[$addr][$connId] = $connId;

			return $this->sessions[$connId];
		}
	}
}

/**
 * Asterisk Call Manager Interface session.
 *
 */
class AsteriskDriverSession extends SocketSession {
	
	/**
	 * The username to access the interface.
	 * @var string
	 */
	public $username;
	
	/**
	 * The password defined in manager interface of server.
	 * @var string
	 */
	public $secret;
	
	/**
	 * Enabling the ability to handle encrypted authentication if set to 'md5'.
	 * @var string|null
	 */
	public $authtype = 'md5';
	
	/**
	 * Property holds a reference to user's object.
	 * @var AppInstance
	 */
	public $context;
	
	/**
	 * Connection's state.
	 * @var float
	 */
	public $cstate = AsteriskDriver::CONN_STATE_START;
	
	/**
	 * Input state.
	 * @var integer
	 */
	public $instate = AsteriskDriver::INPUT_STATE_START;
	
	/**
	 * Received packets.
	 * @var array
	 */
	public $packets = array();
	
	/**
	 * For composite response on action.
	 * @var integer
	 */
	public $cnt = 0;
	
	/**
	 * Stack of callbacks called when response received.
	 * @var array
	 */
	public $callbacks = array();
	
	/**
	 * Assertions for callbacks.
	 * Assertion: if more events may follow as response this is a main part or full
	 * an action complete event indicating that all data has been sent.
	 * 
	 * @var array
	 */
	public $assertions = array();
	
	/**
	 * Callback. Called when received response on challenge action.
	 * @var callback
	 */
	public $onChallenge;
	
	/**
	 * Callback. Called when connection's handshaked.
	 * @var callback
	 */
	public $onConnected;
	
	/**
	 * Callback. Called when asterisk send event.
	 * @var callback
	 */
	public $onEvent;
	
	/**
	 * Callback. Called when error occured.
	 * @var callback
	 */
	public $onError;
	
	/**
	 * Callback. Called when session finishes.
	 * @var callback
	 */
	public $onFinish;
	
	/**
	 * Beginning of the string in the header or value that indicates whether the save value case.
	 * @var array
	 */
	public $safeCaseValues = array('dialstring', 'callerid');

	/**
	 * Extract key and value pair from line.
	 * @param string $line
	 * @return array
	 */
	protected function extract($line) {
		$e = explode(': ', rtrim($line, "\r\n"), 2);
		$header = strtolower(trim($e[0]));
		$value = isset($e[1]) ? trim($e[1]) : null;
		$safe = false;

		foreach ($this->safeCaseValues as $item) {
			if (stripos($header, $item) === 0) {
				$safe = true;
				break;
			}

			if (stripos($value, $item) === 0) {
				$safe = true;
				break;
			}
		}

		if (!$safe) {
			$value = strtolower($value);
		}

		return array($header, $value);
	}

	/**
	 * Called when new data received.
	 * @param string $buf New received data.
	 * @return void
	 */
	public function stdin($buf) {
		$this->buf .= $buf;

		if ($this->cstate === AsteriskDriver::CONN_STATE_START) {
			$this->cstate = AsteriskDriver::CONN_STATE_GOT_INITIAL_PACKET;
			$this->appInstance->amiVersions[$this->addr] = trim($this->buf);
			$this->auth();
		}
		elseif (strlen($this->buf) < 4) {
			return; // Not enough data buffered yet
		}
		elseif (strpos($this->buf, "\r\n\r\n") !== false) {
			while(($line = $this->gets()) !== false) {
				if ($line == "\r\n") {
					$this->instate = AsteriskDriver::INPUT_STATE_END_OF_PACKET;
					$packet =& $this->packets[$this->cnt];
					$this->cnt++;
				} else {
					$this->instate = AsteriskDriver::INPUT_STATE_PROCESSING;
					list($header, $value) = $this->extract($line);
					$this->packets[$this->cnt][$header] = $value;
				}

				if ((int)$this->cstate === AsteriskDriver::CONN_STATE_AUTH) {
					if ($this->instate == AsteriskDriver::INPUT_STATE_END_OF_PACKET) {
						if ($packet['response'] == 'success') {
							if (
								$this->cstate === AsteriskDriver::CONN_STATE_CHALLENGE_PACKET_SENT
							) {
								if (is_callable($this->onChallenge)) {
									call_user_func($this->onChallenge, $this, $packet['challenge']);
								}
							} else {
								if ($packet['message'] == 'authentication accepted') {
									$this->cstate = AsteriskDriver::CONN_STATE_HANDSHAKED_OK;
									Daemon::$process->log(__METHOD__ . ': Authentication ok. Connected to ' . parse_url($this->addr, PHP_URL_HOST));
									if (is_callable($this->onConnected)) {
										call_user_func($this->onConnected, $this, true);
									}
								}
							}
						} else {
							$this->cstate = AsteriskDriver::CONN_STATE_HANDSHAKED_ERROR;
							Daemon::$process->log(__METHOD__ . ': Authentication failed. Connection to ' . parse_url($this->addr, PHP_URL_HOST) . ' failed.');
							if (is_callable($this->onConnected)) {
								call_user_func($this->onConnected, $this, false);
							}

							$this->finish();
						}
						$this->packets = array();
					}
				}
				elseif ($this->cstate === AsteriskDriver::CONN_STATE_HANDSHAKED_OK) {
					if ($this->instate == AsteriskDriver::INPUT_STATE_END_OF_PACKET) {
						// Event
						if (
							isset($packet['event'])
							&& !isset($packet['actionid'])
						) {
							if (is_callable($this->onEvent)) {
								call_user_func($this->onEvent, $this, $packet);
							}
						}
						// Response
						elseif (isset($packet['actionid'])) {
							$action_id =& $packet['actionid'];

							if (isset($this->callbacks[$action_id])) {
								if (isset($this->assertions[$action_id])) {
									$this->packets[$action_id][] = $packet;

									if (count(array_uintersect_uassoc($this->assertions[$action_id], $packet, 'strcasecmp', 'strcasecmp')) == count($this->assertions[$action_id])) {
										if (is_callable($this->callbacks[$action_id])) {
											call_user_func($this->callbacks[$action_id], $this, $this->packets[$action_id]);
											unset($this->callbacks[$action_id]);
										}

										unset($this->assertions[$action_id]);
										unset($this->packets[$action_id]);
									}
								} else {
									if (is_callable($this->callbacks[$action_id])) {
										call_user_func($this->callbacks[$action_id], $this, $packet);
										unset($this->callbacks[$action_id]);
									}
								}
							}
						}
						unset($packet);
						unset($this->packets[$this->cnt - 1]);
					}
				}
			}
		}
	}

	/**
	 * Execute the given callback when/if the connection is handshaked.
	 * @param $callback
	 * @return void
	 */
	public function onConnected($callback) {
		$this->onConnected = $callback;

		if (
			$this->cstate === AsteriskDriver::CONN_STATE_HANDSHAKED_ERROR
			&& is_callable($this->onConnected)
		) {
			call_user_func($this->onConnected, $this, false);
		}
		elseif (
			$this->cstate === AsteriskDriver::CONN_STATE_HANDSHAKED_OK
			&& is_callable($this->onConnected)
		) {
			call_user_func($this->onConnected, $this, true);
		}
	}

	/**
	 * Send authentication packet
	 * @return void
	 */
	protected function auth() {
		if ($this->cstate !== AsteriskDriver::CONN_STATE_GOT_INITIAL_PACKET) {
			return;
		}

		if (stripos($this->authtype, 'md5') !== false) {
			$this->challenge(function(SocketSession $session, $challenge) {
				$packet = "Action: Login\r\n";
				$packet .= "AuthType: MD5\r\n";
				$packet .= "Username: " . $session->username . "\r\n";
				$packet .= "Key: " . md5($challenge . $session->secret) . "\r\n";
				$packet .= "Events: on\r\n";
				$packet .= "\r\n";
				$session->cstate = AsteriskDriver::CONN_STATE_LOGIN_PACKET_SENT_AFTER_CHALLENGE;
				$session->sendPacket($packet);
			});
		} else {
			$this->login();
		}
	}

	/**
	 * Action: Login
	 * Synopsis: Login Manager
	 * Privilege: <none>
	 *
	 * @return void
	 */
	protected function login() {
		$packet = "Action: login\r\n";
		$packet .= "Username: " . $this->username . "\r\n";
		$packet .= "Secret: " . $this->secret . "\r\n";
		$packet .= "Events: on\r\n";
		$packet .= "\r\n";
		$this->cstate = AsteriskDriver::CONN_STATE_LOGIN_PACKET_SENT;
		$this->sendPacket($packet);
	}

	/**
	 * Action: Challenge
	 * Synopsis: Generate Challenge for MD5 Auth
	 * Privilege: <none>
	 *
	 * @return void
	 */
	protected function challenge($callback) {
		$this->onChallenge = $callback;
		$packet = "Action: Challenge\r\n";
		$packet .= "AuthType: MD5\r\n";
		$packet .= "\r\n";
		$this->cstate = AsteriskDriver::CONN_STATE_CHALLENGE_PACKET_SENT;
		$this->sendPacket($packet);
	}

	/**
	 * Action: SIPpeers
	 * Synopsis: List SIP peers (text format)
	 * Privilege: system,reporting,all
	 * Description: Lists SIP peers in text format with details on current status.
	 * Peerlist will follow as separate events, followed by a final event called
	 * PeerlistComplete.
	 * Variables:
	 * ActionID: <id>	Action ID for this transaction. Will be returned.
	 *
	 * @param $callback Callback called when response received.
	 * @return void
	 */
	public function getSipPeers($callback) {
		$this->command("Action: SIPpeers\r\n", $callback, array('event' => 'peerlistcomplete'));
	}

	/**
	 * Action: IAXpeerlist
	 * Synopsis: List IAX Peers
	 * Privilege: system,reporting,all
	 *
	 * @param $callback Callback called when response received.
	 * @return void
	 */
	public function getIaxPeers($callback) {
		$this->command("Action: IAXpeerlist\r\n", $callback, array('event' => 'peerlistcomplete'));
	}

	/**
	 * Action: GetConfig
	 * Synopsis: Retrieve configuration
	 * Privilege: system,config,all
	 * Description: A 'GetConfig' action will dump the contents of a configuration
	 * file by category and contents or optionally by specified category only.
	 * Variables: (Names marked with * are required)
	 *   *Filename: Configuration filename (e.g. foo.conf)
	 *   Category: Category in configuration file
	 *
	 * @param $callback Callback called when response received.
	 * @return void
	 */
	public function getConfig($filename, $callback) {
		$this->command("Action: GetConfig\r\nFilename: " . trim($filename) . "\r\n", $callback);
	}

	/**
	 * Action: GetConfigJSON
	 * Synopsis: Retrieve configuration
	 * Privilege: system,config,all
	 * Description: A 'GetConfigJSON' action will dump the contents of a configuration
	 * file by category and contents in JSON format.  This only makes sense to be used
	 * using rawman over the HTTP interface.
	 * Variables:
	 *    Filename: Configuration filename (e.g. foo.conf)
	 *
	 * @param $callback Callback called when response received.
	 * @return void
	 */
	public function getConfigJSON($filename, $callback) {
		$this->command("Action: GetConfigJSON\r\nFilename: " . trim($filename) . "\r\n", $callback);
	}
	
	/**
	 * Action: Setvar
	 * Synopsis: Set Channel Variable
	 * Privilege: call,all
	 * Description: Set a global or local channel variable.
	 * Variables: (Names marked with * are required)
	 * Channel: Channel to set variable for
	 *  *Variable: Variable name
	 *  *Value: Value
	 */
	public function setVar($channel, $variable, $value, $callback) {
		$cmd = "Action: SetVar\r\n";
		if($channel) {
			$cmd .= "Channel: " . trim($channel) . "\r\n";
		}
		if(isset($variable, $value)) {
			$cmd .= "Variable: " . trim($variable) . "\r\n";
			$cmd .= "Value: " . trim($value) . "\r\n";
			$this->command($cmd, $callback);
		}
	}
	
	/**
	 * Action: CoreShowChannels
	 * Synopsis: List currently active channels
	 * Privilege: system,reporting,all
	 * Description: List currently defined channels and some information
	 *        about them.
	 * Variables:
	 *        ActionID: Optional Action id for message matching.
	 */
	public function coreShowChannels($callback) {
		$this->command("Action: CoreShowChannels\r\n", $callback, array('event' => 'coreshowchannelscomplete', 'eventlist' => 'complete'));
	}

	/**
	 * Action: Status
	 * Synopsis: Lists channel status
	 * Privilege: system,call,reporting,all
	 * Description: Lists channel status along with requested channel vars.
	 * Variables: (Names marked with * are required)
		*Channel: Name of the channel to query for status
	 *	Variables: Comma ',' separated list of variables to include
	 * ActionID: Optional ID for this transaction
	 * Will return the status information of each channel along with the
	 * value for the specified channel variables.
	 */
	public function status($callback, $channel = null) {
		$cmd = "Action: Status\r\n";
		if($channel !== null) {
			$cmd .= "Channel: " . trim($channel) . "\r\n";
		}
		$this->command($cmd, $callback, array('event' => 'statuscomplete'));
	}

	/**
	 * Action: Redirect
	 * Synopsis: Redirect (transfer) a call
	 * Privilege: call,all
	 * Description: Redirect (transfer) a call.
	 * Variables: (Names marked with * are required)
	 * *Channel: Channel to redirect
	 *  ExtraChannel: Second call leg to transfer (optional)
	 * *Exten: Extension to transfer to
	 * *Context: Context to transfer to
	 * *Priority: Priority to transfer to
	 * ActionID: Optional Action id for message matching.
	 *
	 * @param array $params
	 * @param $callback Callback called when response received.
	 * @return void
	 */
	public function redirect(array $params, $callback) {
		$this->command("Action: Redirect\r\n" . $this->implodeParams($params), $callback);
	}

	/**
	 * Action: Ping
	 * Description: A 'Ping' action will ellicit a 'Pong' response.  Used to keep the
	 *   manager connection open.
	 * Variables: NONE
	 *
	 * @param $callback Callback called when response received.
	 * @return void
	 */
	public function ping($callback) {
		$this->command("Action: Ping\r\n", $callback);
	}

	/**
	 * For almost any actions in Action: ListCommands
	 * Privilege: depends on $action
	 *
	 * @param string $action
	 * @param $callback Callback called when response received.
	 * @param array|null $params
	 * @param array|null $assertion If more events may follow as response this is a main part or full an action complete event indicating that all data has been sent.
	 * @return void
	 */
	public function action($action, $callback, array $params = null, array $assertion = null) {
		$action = trim($action);
		$this->command("Action: {$action}\r\n" . ($params ? $this->implodeParams($params) : ''), $callback, $assertion);
	}

	/**
	 * Action: Logoff
	 * Synopsis: Logoff Manager
	 * Privilege: <none>
	 * Description: Logoff this manager session
	 * Variables: NONE
	 *
	 * @param $callback Optional callback called when response received.
	 * @return void
	 */
	public function logoff($callback = null) {
		$this->command("Action: Logoff\r\n", $callback);
	}

	/**
	 * Called when event occured.
	 * @param $callback
	 * @return void
	 */
	public function onEvent($callback) {
		$this->onEvent = $callback;
	}

	/**
	 * Called when error occured.
	 * @param $callback
	 * @return void
	 */
	public function onError($callback) {
		$this->onError = $callback;
	}

	/**
	 * Generate a unique ID.
	 * @return Returns the unique identifier, as a string. 
	 */
	protected function uniqid() {
		return uniqid(Daemon::$process->pid, true);
	}
	
	/**
	 * Sends a packet.
	 * @param string $pacekt Data
	 * @return void
	 */
	public function sendPacket($packet) {
		$this->write($packet);
	}
	
	/**
	 * Sends arbitrary command.
	 * @param string $packet A packet for sending by the connected client to Asterisk
	 * @param $callback Callback called when response received.
	 * @param array $assertion If more events may follow as response this is a main part or full an action complete event indicating that all data has been sent. 
	 */
	protected function command($packet, $callback, $assertion = null) {
		if ($this->finished) {
			throw new AsteriskDriverSessionFinished;
		}

		if ($this->cstate !== AsteriskDriver::CONN_STATE_HANDSHAKED_OK) {
			return;
		}

		$action_id = $this->uniqid();
		if(!is_callable($callback, true)) {
			$callback = false;
		}
		$this->callbacks[$action_id] = $callback;

		if ($assertion !== null) {
			$this->assertions[$action_id] = $assertion;
		}

		$this->sendPacket($packet . "ActionID: {$action_id}\r\n\r\n");
	}

	/**
	 * Generate AMI packet string from associative array provided.
	 * @param array $params
	 * @return string 
	 */
	protected function implodeParams(array $params) {
		$s = '';
		foreach($params as $header => $value) {
			$s .= trim($header) . ": " . trim($value) . "\r\n";
		}
		return $s;
	}

	/**
	 * Called when session finishes or set onFinish callback.
	 * @param $callback
	 * @return void
	 */
	public function onFinish($callback = null) {
		if (
			$callback !== null
			&& is_callable($callback)
		) {
			$this->onFinish = $callback;
		} else {
			unset($this->appInstance->sessions[$this->connId]);
			unset($this->appInstance->servConn[$this->addr][$this->connId]);

			if (is_callable($this->onFinish)) {
				call_user_func($this->onFinish, $this);
			}
		}
	}
}

class AsteriskDriverSessionFinished extends Exception {}


