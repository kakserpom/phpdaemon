<?php

/**
 * @package NetworkClients
 * @subpackage IRCClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class IRChatClient extends NetworkClient {
	public $identd;
	/**
	 * Setting default config options
	 * Overriden from NetworkClient::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return array(
			'port'			=> 6667,
		);
	}

	public function onReady() {}
		$this->identd = IdentServer::getInstance();
		parent::onReady();
	}

	public static function parseUsermask($mask) {
		preg_match('~^(?:(.*?)!(\~?)(.*?)@)?(.*)$~D', $mask, $m);
		return array(
			'nick' => $m[1],
			'unverified' => $m[2] === '~',
			'user' => $m[3],
			'host' => $m[4],
			'orig' => $mask,
		);
	}
}

class IRChatClientConnection extends NetworkClientConnection {
	public $user 			= 'Guest';     // Username
	public $password 		= '';         // Password
	public $EOL				= "\r\n";
	public $eventHandlers = array();
	public $mode = '';
	public $buffers = array();
	public static $codes = array (
  		'1' => 'RPL_WELCOME',  2 => 'RPL_YOURHOST',
		3 => 'RPL_CREATED', 4 => 'RPL_MYINFO',
		5 => 'RPL_BOUNCE', 200 => 'RPL_TRACELINK',
		201 => 'RPL_TRACECONNECTING',  202 => 'RPL_TRACEHANDSHAKE',
		203 => 'RPL_TRACEUNKNOWN',  204 => 'RPL_TRACEOPERATOR',
		205 => 'RPL_TRACEUSER',  206 => 'RPL_TRACESERVER',
		207 => 'RPL_TRACESERVICE',  208 => 'RPL_TRACENEWTYPE',
		209 => 'RPL_TRACECLASS',  210 => 'RPL_TRACERECONNECT',
		211 => 'RPL_STATSLINKINFO',  212 => 'RPL_STATSCOMMANDS',
		219 => 'RPL_ENDOFSTATS',  221 => 'RPL_UMODEIS',
		234 => 'RPL_SERVLIST',  235 => 'RPL_SERVLISTEND',
		242 => 'RPL_STATSUPTIME',  243 => 'RPL_STATSOLINE',
		250 => 'RPL_STATSCONN',
		251 => 'RPL_LUSERCLIENT',  252 => 'RPL_LUSEROP',
		253 => 'RPL_LUSERUNKNOWN',  254 => 'RPL_LUSERCHANNELS',
		255 => 'RPL_LUSERME',  256 => 'RPL_ADMINME',
		257 => 'RPL_ADMINLOC1',  258 => 'RPL_ADMINLOC2',
		259 => 'RPL_ADMINEMAIL',  261 => 'RPL_TRACELOG',
		262 => 'RPL_TRACEEND',  263 => 'RPL_TRYAGAIN',
		265 => 'RRPL_LOCALUSERS', 266 => 'RPL_GLOBALUSERS',
		301 => 'RPL_AWAY',  302 => 'RPL_USERHOST',
		303 => 'RPL_ISON',  305 => 'RPL_UNAWAY',
		306 => 'RPL_NOWAWAY',  311 => 'RPL_WHOISUSER',
		312 => 'RPL_WHOISSERVER',  313 => 'RPL_WHOISOPERATOR',
  		314 => 'RPL_WHOWASUSER',  315 => 'RPL_ENDOFWHO',
  		317 => 'RPL_WHOISIDLE',  318 => 'RPL_ENDOFWHOIS',
  		319 => 'RPL_WHOISCHANNELS',  321 => 'RPL_LISTSTART',
  		322 => 'RPL_LIST',  323 => 'RPL_LISTEND',
  		324 => 'RPL_CHANNELMODEIS',  325 => 'RPL_UNIQOPIS',
  		331 => 'RPL_NOTOPIC',  332 => 'RPL_TOPIC',  333 => 'RPL_TOPIC_TS',
  		341 => 'RPL_INVITING',  342 => 'RPL_SUMMONING',
  		346 => 'RPL_INVITELIST',  347 => 'RPL_ENDOFINVITELIST',
  		348 => 'RPL_EXCEPTLIST',  349 => 'RPL_ENDOFEXCEPTLIST',
  		351 => 'RPL_VERSION',  352 => 'RPL_WHOREPLY',
  		353 => 'RPL_NAMREPLY',  364 => 'RPL_LINKS',
  		365 => 'RPL_ENDOFLINKS',  366 => 'RPL_ENDOFNAMES',
  		367 => 'RPL_BANLIST',  368 => 'RPL_ENDOFBANLIST',
  		369 => 'RPL_ENDOFWHOWAS',  371 => 'RPL_INFO',
  		372 => 'RPL_MOTD',  374 => 'RPL_ENDOFINFO',
  		375 => 'RPL_MOTDSTART',  376 => 'RPL_ENDOFMOTD',
  		381 => 'RPL_YOUREOPER',  382 => 'RPL_REHASHING',
  		383 => 'RPL_YOURESERVICE',  391 => 'RPL_TIME',
  		392 => 'RPL_USERSSTART',  393 => 'RPL_USERS',
  		394 => 'RPL_ENDOFUSERS',  395 => 'RPL_NOUSERS',
  		401 => 'ERR_NOSUCHNICK',  402 => 'ERR_NOSUCHSERVER',
  		403 => 'ERR_NOSUCHCHANNEL',  404 => 'ERR_CANNOTSENDTOCHAN',
  		405 => 'ERR_TOOMANYCHANNELS',  406 => 'ERR_WASNOSUCHNICK',
  		407 => 'ERR_TOOMANYTARGETS',  408 => 'ERR_NOSUCHSERVICE',
  		409 => 'ERR_NOORIGIN',  411 => 'ERR_NORECIPIENT',
  		412 => 'ERR_NOTEXTTOSEND',  413 => 'ERR_NOTOPLEVEL',
  		414 => 'ERR_WILDTOPLEVEL',  415 => 'ERR_BADMASK',
  		421 => 'ERR_UNKNOWNCOMMAND',  422 => 'ERR_NOMOTD',
  		423 => 'ERR_NOADMININFO',  424 => 'ERR_FILEERROR',
  		431 => 'ERR_NONICKNAMEGIVEN',  432 => 'ERR_ERRONEUSNICKNAME',
  		433 => 'ERR_NICKNAMEINUSE',  436 => 'ERR_NICKCOLLISION',
  		437 => 'ERR_UNAVAILRESOURCE',  441 => 'ERR_USERNOTINCHANNEL',
  		442 => 'ERR_NOTONCHANNEL',  443 => 'ERR_USERONCHANNEL',
  		444 => 'ERR_NOLOGIN',  445 => 'ERR_SUMMONDISABLED',
  		446 => 'ERR_USERSDISABLED',  451 => 'ERR_NOTREGISTERED',
  		461 => 'ERR_NEEDMOREPARAMS',  462 => 'ERR_ALREADYREGISTRED',
		463 => 'ERR_NOPERMFORHOST',  		464 => 'ERR_PASSWDMISMATCH',
  		465 => 'ERR_YOUREBANNEDCREEP',  		466 => 'ERR_YOUWILLBEBANNED',
  		467 => 'ERR_KEYSET',  		471 => 'ERR_CHANNELISFULL',
  		472 => 'ERR_UNKNOWNMODE',  		473 => 'ERR_INVITEONLYCHAN',
  		474 => 'ERR_BANNEDFROMCHAN',  		475 => 'ERR_BADCHANNELKEY',
  		476 => 'ERR_BADCHANMASK',  		477 => 'ERR_NOCHANMODES',
  		478 => 'ERR_BANLISTFULL',  		481 => 'ERR_NOPRIVILEGES',
  		482 => 'ERR_CHANOPRIVSNEEDED',  		483 => 'ERR_CANTKILLSERVER',
  		484 => 'ERR_RESTRICTED',  		485 => 'ERR_UNIQOPPRIVSNEEDED',
  		491 => 'ERR_NOOPERHOST',  		501 => 'ERR_UMODEUNKNOWNFLAG',
  		502 => 'ERR_USERSDONTMATCH',
	);
	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		$this->pool->identd->registerPair($this->locPort, $this->port, 'UNIX : '.$this->user)
		Daemon::log($this->locPort);
		return;
		$this->command('USER', $this->user, 0, '*', 'John Doe');
		$this->command('NICK', $this->path);
		if (strlen($this->password)) {
			$this->message('NickServ', 'IDENTIFY '.$this->password);
		}
		$this->keepaliveTimer = setTimeout(function($timer) {
			$this->ping();
		}, 1e6 * 40);
	}

	public function command($cmd) {
		$line = $cmd;
		for ($i = 1, $s = func_num_args(); $i < $s; ++$i) {
			$line .= ($i + 1 === $s ? ' :' : ' ').func_get_arg($i);
		}
		$this->writeln($line);
		if (!in_array($cmd, array('PING'))) {
			Daemon::log('>->->-> '.$line);
		}
	}

	public function join($channels) {
		$this->command('JOIN', $channels);
	}

	public function part($channels) {
		$this->command('PART', $channels, $msg);
	}
	
	/**
	 * Called when session finishes
	 * @return void
	 */
	public function onFinish() {
		parent::onFinish();
		$this->event('disconnect');
		Timer::remove($this->keepaliveTimer);
	}

	public function ping() {
		$this->command('PING', 'phpdaemon');
	}

	public function message($to, $msg) {
		$this->command('PRIVMSG', $to, $msg);
	}


	public function bind($event, $cb) {
		if (!isset($this->eventHandlers[$event])) {
			$this->eventHandlers[$event] = array();
		}
		$this->eventHandlers[$event][] = $cb;
	}

	public function unbind($event, $cb) {
		if (!isset($this->eventHandlers[$event])) {
			return false;
		}
		if (($p = array_search($cb, $this->eventHandlers[$event], true)) === false) {
			return false;
		}
		unset($this->eventHandlers[$event][$p]);
	}

	public function event() {
		$args = func_get_args();
		$name = array_shift($args);
		array_unshift($args, $this);
		if (isset($this->eventHandlers[$name])) {
			foreach ($this->eventHandlers[$name] as $cb) {
				call_user_func_array($cb, $args);
			}
		}
	}

	public function addMode($channel, $target, $mode) {
		if ($channel) {
			$this->channel($channel)->addMode($target, $mode);
		} else {
			if (strpos($this->mode, $mode) === false) {
				$this->mode .= $mode;
			}
		}
	}

	public function removeMode($channel, $target, $mode) {
		if ($channel) {
			$this->channel($channel)->removeMode($target, $mode);
		}
		$this->mode = str_replace($mode, '', $this->mode);
	}

	public function onCommand($from, $cmd, $args) {
		$log = false;
		if ($cmd === 'RPL_WELCOME') {
			if ($this->onConnected) {
				$this->connected = true;
				$this->onConnected->executeAll($this);
				$this->onConnected = null;
			}
		}
		elseif ($cmd === 'NOTICE') {
			list ($target, $text) = $args;
			$log = true;
			$this->event('notice', $target, $text);
		}
		elseif ($cmd == 'RPL_YOURHOST') {
		}
		elseif ($cmd === 'RPL_MOTDSTART') {
			$this->motd = $args[1];
		}
		elseif ($cmd === 'RPL_MOTD') {
			$this->motd .= "\r\n" . $args[1];
		}
		elseif ($cmd === 'RPL_ENDOFMOTD') {
			$this->motd .= "\r\n";// . $args[1];
			$this->event('motd', $this->motd);
		}
		elseif ($cmd === 'JOIN') {
			list ($channel) = $args;
			$chan = $this->channel($channel);
			IRChatClientChannelParticipant::instance($chan, $from['nick'])->setUsermask($from);
		}
		elseif ($cmd === 'RPL_NAMREPLY') {
			list($nick, $channelName) = $args;
			if (!isset($this->buffers[$cmd])) {
				$this->buffers[$cmd] = array();
			}
			if (!isset($this->buffers[$cmd][$channelName])) {
				$this->buffers[$cmd][$channelName] = new SplStack;
			}
			$this->buffers[$cmd][$channelName]->push($args);
		}
		elseif ($cmd === 'RPL_ENDOFNAMES') {
			list($nick, $channelName, $text) = $args;
			if (!isset($this->buffers[$cmd][$channelName])) {
				return;
			}
			$buf = $this->buffers[$cmd][$channelName];
			$chan = null;
			while (!$buf->isEmpty()) {
				list($nick, $chantype, $channelName, $participants) = $buf->shift();
				if (!$chan) {
					$chan = $this->channel($channelName)->setType($chantype);
					$chan->each('remove');
				}
				preg_match_all('~([\+%@]?)\S+~', $participants, $matches, PREG_SET_ORDER);

				foreach ($matches as $m) {
					list(, $flag, $nickname) = $m;
					IRChatClientChannelParticipant::instance($chan, $nickname)->setFlag($flag);
				}

			}
			if (!$chan) {
				return;
			}
		}
		elseif ($cmd === 'MODE') {
			if (sizeof($args) === 3) {
				list ($channel, $target, $mode) = $args;
			} else {
				$channel = null;
				list ($target, $mode) = $args;
			}
			if ($mode[0] === '+') {
				$this->addMode($channel, $target, binarySubstr($mode, 1));
			} elseif ($mode[0] === '-') {
				$this->removeMode($channel, $target, binarySubstr($mode, 1));
			}
		}
		elseif ($cmd === 'RPL_CREATED') {
			list($to, $this->created) = $args;
		}
		elseif ($cmd === 'RPL_MYINFO') {
			list($to, $this->servername, $this->serverver, $this->availUserModes, $this->availChanModes) = $args;
		}
		elseif ($cmd === 'PRIVMSG') {
			list ($target, $body) = $args;
			$msg = array(
				'from' => $from,
				'to' => $target,
				'body' => $body,
				'private' => substr($target, 0, 1) !== '#',
			);
			$this->event($msg['private'] ? 'privateMsg' : 'channelMsg', $msg);
			if (!$msg['private']) {
				$this->channel($target)->event('msg', $msg);
			}
		}
		elseif ($cmd === "PONG") {}
		else {
			$log = true;
		}
		if ($log) {
			Daemon::$process->log('<-<-<-< '.$cmd.': '.json_encode($args). ' ('.$from['orig'].'	)');
		}
	}

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	*/
	public function stdin($buf) {
		Timer::setTimeout($this->keepaliveTimer);
		$this->buf .= $buf;
		while (($line = $this->gets()) !== false) {
			if ($line === $this->EOL) {
				continue;
			}
			if (strlen($line) > 512) {
				Daemon::$process->log('IRChatClientConnection error: buffer overflow.');
				$this->finish();
				return;
			}
			$line = binarySubstr($line, 0, -strlen($this->EOL));
			$p = strpos($line, ':', 1);
			$max = $p ? substr_count($line, "\x20", 0, $p) + 1 : 18;
			$e = explode("\x20", $line, $max);
			$i = 0;
			$from = IRChatClient::parseUsermask($e[$i]{0} === ':' ? binarySubstr($e[$i++], 1) : null);
			$cmd = $e[$i++];
			$args = array();

			for ($s = min(sizeof($e), 14); $i < $s; ++$i) {
				if ($e[$i][0] === ':') {
					$args[] = binarySubstr($e[$i], 1);
					break;
				}
				$args[] = $e[$i];
			}

			if (ctype_digit($cmd)) {
				$code = (int) $cmd;
				$cmd = isset(self::$codes[$code]) ? self::$codes[$code] : 'UNKNOWN-'.$code;
			}
			$this->onCommand($from, $cmd, $args);
		}
		if (strlen($this->buf) > 512) {
			Daemon::$process->log('IRChatClientConnection error: buffer overflow.');
			$this->finish();
		}
	}

	public function channel($chan) {
		if (isset($this->channels[$chan])) {
			return $this->channels[$chan];
		}
		return $this->channels[$chan] = new IRChatClientChannel($this, $chan);
	}
}
class IRChatClientChannel extends ObjectStorage {
	public $irc;
	public $name;
	public $eventHandlers;
	public $nicknames = array();
	public $self;
	public $type;
	public function ___construct($irc, $name) {
		$this->irc = $irc;
		$this->name = $name;
	}
	

	public function addMode($nick, $mode) {
		if (!isset($this->nicknames[$nick])) {
			return;
		}
		$participant = $this->nicknames[$nick];
		if (strpos($participant->mode, $mode) === false) {
			$participant->mode .= $mode;
		}
		$participant->onModeUpdate();
	}

	public function removeMode($target, $mode) {
		if (!isset($this->nicknames[$nick])) {
			return;
		}
		$participant = $this->nicknames[$nick];
		$participant->mode = str_replace($mode, '', $participant->mode);
		$participant->onModeUpdate();
	}

	public function bind($event, $cb) {
		if (!isset($this->eventHandlers[$event])) {
			$this->eventHandlers[$event] = array();
		}
		$this->eventHandlers[$event][] = $cb;
	}

	public function unbind($event, $cb) {
		if (!isset($this->eventHandlers[$event])) {
			return false;
		}
		if (($p = array_search($cb, $this->eventHandlers[$event], true)) === false) {
			return false;
		}
		unset($this->eventHandlers[$event][$p]);
	}

	public function event() {
		$args = func_get_args();
		$name = array_shift($args);
		array_unshift($args, $this);
		if (isset($this->eventHandlers[$name])) {
			foreach ($this->eventHandlers[$name] as $cb) {
				call_user_func_array($cb, $args);
			}
		}
	}

	public function join() {
		$this->irc->join($this->name);
	}
	public function part($msg = null) {
		$this->irc->part($this->name, $msg);
	}

	public function setType($type) {
		$this->type = $type;
		return $this;
	}

	public function detach($obj) {
		parent::detach($obj);
		unset($this->nicknames[$obj->nick]);
	}	
}
class IRChatClientChannelParticipant {
	public $channel;
	public $nick;
	public $mask;
	public $flag;
	public $mode;

	public function setFlag($flag) {
		$this->flag = $flag;
		if ($flag === '@') {
			$this->mode = 'o';
		}
		elseif ($flag === '%') {
			$this->mode = 'h';
		}
		elseif ($flag === '+') {
			$this->mode = 'v';
		}
		return $this;
	}
	public function __construct($channel, $nick) {
		$this->channel = $channel;
		$this->setNick($nick);
		$this->channel->attach($this);
	}
	
	public function onModeUpdate() {
		if (strpos($this->mode, 'o') !== false) {
			$this->flag = '@';
		}
		elseif (strpos($this->mode, 'h') !== false) {
			$this->flag = '%';
		}
		elseif (strpos($this->mode, 'v') !== false) {
			$this->flag = '+';
		} else {
			$this->flag = '';
		}
	}

	public function setUsermask($mask) {
		if (is_string($mask)) {
			$mask = IRChatClient::parseUsermask($mask);
		}
		$this->mask = $mask['orig'];
		$this->setNick($mask['nick']);
	}

	public static function instance($channel, $nick) {
		if (isset($channel->nicknames[$nick])) {
			return $channel->nicknames[$nick];
		}
		$class = get_called_class();
		return new $class($channel, $nick);
	}
	public function setNick($nick) {
		if ($this->nick === $nick) {
			return;
		}
		$this->nick = $nick;
		unset($this->channel->nicknames[$this->nick]);
		$this->nick = $nick;
		$this->channel->nicknames[$this->nick] = $this;	
	}

	public function remove() {
		$this->channel->detach($this);
		unset($this->channel->nicknames[$this->nick]);
	}
	public function chanMessage($msg) {
		$this->channel->message($this->nick.': '.$msg);
	}
}

