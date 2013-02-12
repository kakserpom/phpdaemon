<?php
/**
 * Driver for Asterisk Call Manager/1.1
 *
 * @package NetworkClient
 * @subpackage AsteriskClient
 * 
 * @version 2.0
 * @author Ponomarev Dmitry <ponomarev.base@gmail.com> (original code)
 * @author TyShkan <denis@tyshkan.ru> (updated code)
 */
class AsteriskClient extends NetworkClient {
	
	/**
	 * Asterisk Call Manager Interface versions for each session.
	 * @var array
	 */
	public $amiVersions = [];

	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			// default server
			'server' => 'tcp://127.0.0.1',
			// default port
			'port'   => 5038,
		];
	}
	
}

/**
 * Asterisk Call Manager Connection.
 *
 */
class AsteriskClientConnection extends NetworkClientConnection {

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
	 * Connection's state.
	 * @var float
	 */
	public $state = self::CONN_STATE_START;
	
	/**
	 * Input state.
	 * @var integer
	 */
	public $instate = self::INPUT_STATE_START;
	
	/**
	 * Received packets.
	 * @var array
	 */
	public $packets = [];
	
	/**
	 * For composite response on action.
	 * @var integer
	 */
	public $cnt = 0;
	
	/**
	 * Stack of callbacks called when response received.
	 * @var array
	 */
	public $callbacks = [];
	
	/**
	 * Assertions for callbacks.
	 * Assertion: if more events may follow as response this is a main part or full
	 * an action complete event indicating that all data has been sent.
	 * 
	 * @var array
	 */
	public $assertions = [];
	
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
	 * Beginning of the string in the header or value that indicates whether the save value case.
	 * @var array
	 */
	public $safeCaseValues = ['dialstring', 'callerid'];
	
	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		parent::onReady();
		
		if ($this->url === null) {
			return;
		}
		
		if ($this->connected && !$this->busy) {
			$this->pool->servConnFree[$this->url]->attach($this);
		}
		
		$this->username = $this->pool->config->username->value;
		$this->secret = $this->pool->config->secret->value;
	}
	
	/**
	 * Called when the worker is going to shutdown
	 * @return boolean Ready to shutdown?
	 */
	public function gracefulShutdown() {
		if ($this->finished) {
			return !$this->sending;
		}
		
		$this->logoff();
		
		$this->finish();
		
		return false;
	}
	
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
		
		if ($this->state === self::CONN_STATE_START) {
			$this->state = self::CONN_STATE_GOT_INITIAL_PACKET;
			$this->pool->amiVersions[$this->addr] = trim($this->buf);
			$this->auth();
			return;
		}
		
		if (strlen($this->buf) < 4) {
			return; // Not enough data buffered yet
		}
		
		while(($line = $this->gets()) !== false) {
			if ($line == "\r\n") {
				$this->instate = self::INPUT_STATE_END_OF_PACKET;
				$packet =& $this->packets[$this->cnt];
				++$this->cnt;
			} else {
				$this->instate = self::INPUT_STATE_PROCESSING;
				list($header, $value) = $this->extract($line);
				$this->packets[$this->cnt][$header] = $value;
			}

			if ((int)$this->state === self::CONN_STATE_AUTH) {
				if ($this->instate == self::INPUT_STATE_END_OF_PACKET) {
					if ($packet['response'] == 'success') {
						if ($this->state === self::CONN_STATE_CHALLENGE_PACKET_SENT) {
							if (is_callable($this->onChallenge)) {
								call_user_func($this->onChallenge, $this, $packet['challenge']);
							}
						} else {
							if ($packet['message'] == 'authentication accepted') {
								$this->state = self::CONN_STATE_HANDSHAKED_OK;
								
								Daemon::$process->log(__METHOD__ . ': Authentication ok. Connected to ' . parse_url($this->addr, PHP_URL_HOST));
								
								if (is_callable($this->onConnected)) {
									call_user_func($this->onConnected, $this, true);
								}
							}
						}
					} else {
						$this->state = self::CONN_STATE_HANDSHAKED_ERROR;
						
						Daemon::$process->log(__METHOD__ . ': Authentication failed. Connection to ' . parse_url($this->addr, PHP_URL_HOST) . ' failed.');
						
						if (is_callable($this->onConnected)) {
							call_user_func($this->onConnected, $this, false);
						}
						
						$this->finish();
					}
					
					$this->packets = [];
				}
			}
			elseif ($this->state === self::CONN_STATE_HANDSHAKED_OK) {
				if ($this->instate == self::INPUT_STATE_END_OF_PACKET) {
					// Event
					if (isset($packet['event']) && !isset($packet['actionid'])) {
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

	/**
	 * Execute the given callback when/if the connection is handshaked.
	 * @param Callback
	 * @return void
	 */
	public function onConnected($cb) {
		if ($this->state === self::CONN_STATE_HANDSHAKED_ERROR) {
			call_user_func($cb, $this, false);
		} elseif ($this->state === self::CONN_STATE_HANDSHAKED_OK) {
			call_user_func($cb, $this, true);
		} else {
			if (!$this->onConnected) {
				$this->onConnected = new StackCallbacks;
			}
			
			$this->onConnected->push($cb);
		}
	}

	/**
	 * Send authentication packet
	 * @return void
	 */
	protected function auth() {
		if ($this->state !== self::CONN_STATE_GOT_INITIAL_PACKET) {
			return;
		}

		if (stripos($this->authtype, 'md5') !== false) {
			$this->challenge(function($conn, $challenge) {
				$packet = "Action: Login\r\n"
				. "AuthType: MD5\r\n"
				. "Username: " . $this->username . "\r\n"
				. "Key: " . md5($challenge . $this->secret) . "\r\n"
				. "Events: on\r\n"
				. "\r\n";
				
				$this->state = self::CONN_STATE_LOGIN_PACKET_SENT_AFTER_CHALLENGE;
				$this->write($packet);
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
		$this->state = self::CONN_STATE_LOGIN_PACKET_SENT;
		$this->write(
			"Action: login\r\n"
			. "Username: " . $this->username . "\r\n"
			. "Secret: " . $this->secret . "\r\n"
			. "Events: on\r\n"
			. "\r\n"
		);
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
		
		$packet = "Action: Challenge\r\n"
		. "AuthType: MD5\r\n"
		. "\r\n";
		
		$this->state = self::CONN_STATE_CHALLENGE_PACKET_SENT;
		$this->write($packet);
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
		$this->command("Action: SIPpeers\r\n", $callback, ['event' => 'peerlistcomplete']);
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
		$this->command("Action: IAXpeerlist\r\n", $callback, ['event' => 'peerlistcomplete']);
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
		
		if ($channel) {
			$cmd .= "Channel: " . trim($channel) . "\r\n";
		}
		
		if (isset($variable, $value)) {
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
		
		if ($channel !== null) {
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
	 * Sends arbitrary command.
	 * @param string $packet A packet for sending by the connected client to Asterisk
	 * @param $callback Callback called when response received.
	 * @param array $assertion If more events may follow as response this is a main part or full an action complete event indicating that all data has been sent. 
	 */
	protected function command($packet, $callback, $assertion = null) {
		if ($this->finished) {
			throw new AsteriskClientConnectionFinished;
		}

		if ($this->state !== self::CONN_STATE_HANDSHAKED_OK) {
			return;
		}

		$action_id = $this->uniqid();
		
		if (!is_callable($callback, true)) {
			$callback = false;
		}
		
		$this->callbacks[$action_id] = $callback;

		if ($assertion !== null) {
			$this->assertions[$action_id] = $assertion;
		}

		$this->write($packet . "ActionID: {$action_id}\r\n\r\n");
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
}

class AsteriskClientConnectionFinished extends Exception {}
