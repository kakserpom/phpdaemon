<?php

/**
 * @package NetworkClients
 * @subpackage IRCClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class IRCClient extends NetworkClient {
	public $identd;
	public $protologging = false;

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

	public function onReady() {
		$this->identd = IdentServer::getInstance();
		parent::onReady();
	}
}

class IRCClientConnection extends NetworkClientConnection {
	public $user 			= 'Guest';     // Username
	public $password 		= '';         // Password
	public $EOL				= "\r\n";
	public $nick;
	public $realname;
	public $eventHandlers = array();
	public $mode = '';
	public $buffers = array();
	public $servername;
	public $channels = array();
	public $latency;
	public $lastPingTS;
	public $timeout = 300;
	public $bevConnectEnabled = false; // to get local port number

	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		if ($this->pool->identd) {
			$this->pool->identd->registerPair($this->locPort, $this->port, 'UNIX : '.$this->user);
		}
		list($this->nick, $this->realname) = explode('/', $this->path . '/John Doe');
		$this->command('USER', $this->user, 0, '*', $this->realname);
		$this->command('NICK', $this->nick);
		if (strlen($this->password)) {
			$this->message('NickServ', 'IDENTIFY '.$this->password);
		}
	}

	public function command($cmd) {
		if (ctype_digit($cmd)) {
			$cmd = IRC::getCommandByCode((int) $cmd);
		}
		$line = $cmd;
		for ($i = 1, $s = func_num_args(); $i < $s; ++$i) {
			$arg = func_get_arg($i);
			if (($i + 1 === $s) && (strpos($arg, "\x20") !== false)) {
				$line .= ' :';
			}
			else {
				$line .= ' ';
			}
			$line .= $arg;
		}
		$this->writeln($line);
		if ($this->pool->protologging) {
			Daemon::log('->->->-> '.$line);
		}
	}

	public function commandArr($cmd, $args = array()) {
		if (!is_array($args)) {
			return false;
		}
		if (ctype_digit($cmd)) {
			$cmd = IRC::getCommandByCode((int) $cmd);
		}
		$line = $cmd;
		for ($i = 0, $s = sizeof($args); $i < $s; ++$i) {
			if (($i + 1 === $s) && (strpos($args[$i], "\x20") !== false)) {
				$line .= ' :';
			}
			else {
				$line .= ' ';
			}
			$line .= $args[$i];
		}
		$this->writeln($line);
		if ($this->pool->protologging && !in_array($cmd, array('PONG'))) {
			Daemon::log('->->->-> '.$line);
		}
	}


	public function join($channels) {
		if (!is_array($channels)) {
			$channels = array_map('trim', explode(',', $channels));
		}
		foreach ($channels as $chan) {
			$this->command('JOIN', $chan);
		}
	}

	public function part($channels, $msg = null) {
		$this->command('PART', $channels, $msg);
	}
	
	/**
	 * Called when connection finishes
	 * @return void
	 */
	public function onFinish() {
		if ($this->pool->identd) {
			$this->pool->identd->unregisterPair($this->locPort, $this->port);
		}
		$this->event('disconnect');
		parent::onFinish();
	}

	public function ping() {
		$this->lastPingTS = microtime(true);
		$this->writeln('PING :'.$this->servername);
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

	public function unbind($event, $cb = null) {
		if (!isset($this->eventHandlers[$event])) {
			return false;
		}
		if ($cb === null) {
			unset($this->eventHandlers[$event]);
			return true;
		}
		if (($p = array_search($cb, $this->eventHandlers[$event], true)) === false) {
			return false;
		}
		unset($this->eventHandlers[$event][$p]);
		return true;
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
		$this->event('command', $from, $cmd, $args);
		if ($cmd === 'RPL_WELCOME') {
			$this->servername = $from['orig'];
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
			return;
		}
		elseif ($cmd === 'RPL_MOTD') {
			$this->motd .= "\r\n" . $args[1];
			return;
		}
		elseif ($cmd === 'RPL_ENDOFMOTD') {
			$this->motd .= "\r\n";// . $args[1];
			$this->event('motd', $this->motd);
			return;
		}
		elseif ($cmd === 'JOIN') {
			list ($channel) = $args;
			$chan = $this->channel($channel);
			IRCClientChannelParticipant::instance($chan, $from['nick'])->setUsermask($from);
		}
		elseif ($cmd === 'NICK') {
			list ($newNick) = $args;
			foreach ($this->channels as $channel) {
				if (isset($channel->nicknames[$from['nick']])) {
					$channel->nicknames[$from['nick']]->setNick($newNick);
				}
			}
		}
		elseif ($cmd === 'QUIT') {
			$args[] = null;
			list ($msg) = $args;
			foreach ($this->channels as $channel) {
				if (isset($channel->nicknames[$from['nick']])) {
					$channel->nicknames[$from['nick']]->destroy();
				}
			}
		}
		elseif ($cmd === 'PART') {
			$args[] = null;
			list ($channel, $msg) = $args;
			if ($chan = $this->channelIfExists($channel)) {
				$chan->onPart($from, $msg);
			}
		}
		elseif ($cmd === 'RPL_NAMREPLY') {
			$bufName = 'RPL_NAMREPLY';
			list($myNick, $chanType, $channelName) = $args;
			$this->channel($channelName)->setChanType($chanType);
			if (!isset($this->buffers[$bufName])) {
				$this->buffers[$bufName] = array();
			}
			if (!isset($this->buffers[$bufName][$channelName])) {
				$this->buffers[$bufName][$channelName] = new SplStack;
			}
			$this->buffers[$bufName][$channelName]->push($args);
		}
		elseif ($cmd === 'RPL_ENDOFNAMES') {
			$bufName = 'RPL_NAMREPLY';
			list($nick, $channelName, $text) = $args;
			if (!isset($this->buffers[$bufName][$channelName])) {
				return;
			}
			$buf = $this->buffers[$bufName][$channelName];
			$chan = null;
			while (!$buf->isEmpty()) {
				$shift = $buf->shift();
				list($nick, $chantype, $channelName, $participants) = $shift;
				if (!$chan) {
					$chan = $this->channel($channelName)->setType($chantype);
					$chan->each('destroy');
				}
				preg_match_all('~([\+%@]?)(\S+)~', $participants, $matches, PREG_SET_ORDER);

				foreach ($matches as $m) {
					list(, $flag, $nickname) = $m;
					IRCClientChannelParticipant::instance($chan, $nickname)->setFlag($flag);
				}

			}
		}
		elseif ($cmd === 'RPL_WHOREPLY') {
			if (sizeof($args) < 7) {

			}
			list($myNick, $channelName, $user, $host, $server, $nick, $mode, $hopCountRealName) = $args;
			list ($hopCount, $realName) = explode("\x20", $hopCountRealName);
			if ($channel = $this->channelIfExists($channelName)) {
				IRCClientChannelParticipant::instance($channel, $nick)
				->setUsermask($nick . '!' . $user . '@' . $server)
				->setFlag($mode);
			}
		}
		elseif ($cmd === 'RPL_TOPIC') {
			list($myNick, $channelName, $text) = $args;
			if ($channel = $this->channelIfExists($channelName)) {
				$channel->setTopic($text);
			}	
		}
		elseif ($cmd === 'RPL_ENDOFWHO') {
			list($myNick, $channelName, $text) = $args;
		}
		elseif ($cmd === 'MODE') {
			if (sizeof($args) === 3) {
				list ($channel, $mode, $target) = $args;
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
			$this->event('msg', $msg);
			if (!$msg['private']) {
				$this->channel($target)->event('msg', $msg);
			}
		}
		elseif ($cmd === 'PING') {
			$this->writeln(isset($args[0]) ? 'PONG :'.$args[0] : 'PONG');
		}
		elseif ($cmd === 'PONG') {
			if ($this->lastPingTS) {
				$this->latency = microtime(true) - $this->lastPingTS;
				$this->lastPingTS = null;
			}
			return;
		}
		if ($this->pool->protologging) {
			Daemon::$process->log('<-<-<-< '.$cmd.': '.json_encode($args). ' ('.json_encode($from['orig']).') (' . json_encode($this->lastLine) . ')');
		}
	}

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	*/
	public function stdin($buf) {
		$this->buf .= $buf;
		while (($line = $this->gets()) !== false) {
			if ($line === $this->EOL) {
				continue;
			}
			if (strlen($line) > 512) {
				Daemon::$process->log('IRCClientConnection error: buffer overflow.');
				$this->finish();
				return;
			}
			$line = binarySubstr($line, 0, -strlen($this->EOL));
			$p = strpos($line, ' :', 1);
			$max = $p !== false ? substr_count($line, "\x20", 0, $p + 1) + 1 : 18;
			$e = explode("\x20", $line, $max);
			$i = 0;
			$from = IRC::parseUsermask($e[$i]{0} === ':' ? binarySubstr($e[$i++], 1) : null);
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
				$cmd = isset(IRC::$codes[$code]) ? IRC::$codes[$code] : $code;
			}
			$this->lastLine = $line;
			$this->onCommand($from, $cmd, $args);
		}
		if (strlen($this->buf) > 512) {
			Daemon::$process->log('IRCClientConnection error: buffer overflow.');
			$this->finish();
		}
	}

	public function channel($chan) {
		if (isset($this->channels[$chan])) {
			return $this->channels[$chan];
		}
		return $this->channels[$chan] = new IRCClientChannel($this, $chan);
	}

	public function channelIfExists($chan) {
		if (isset($this->channels[$chan])) {
			return $this->channels[$chan];
		}
		return false;
	}
}
class IRCClientChannel extends ObjectStorage {
	public $irc;
	public $name;
	public $eventHandlers;
	public $nicknames = array();
	public $self;
	public $type;
	public $topic;
	public function __construct($irc, $name) {
		$this->irc = $irc;
		$this->name = $name;
	}

	public function who() {
		$this->irc->command('WHO', $this->name);
	}

	public function onPart($mask, $msg = null) {
		if (is_string($mask)) {
			$mask = IRC::parseUsermask($mask);
		}
		if (($mask['nick'] === $this->irc->nick) && ($mask['user'] === $this->irc->user)) {
			$this->destroy();
		} else {
			unset($this->nicknames[$mask['nick']]);
		}
	}
	public function setChanType($type) {
		$this->type = $type;
	}

	public function exportNicksArray() {
		$nicks = array();
		foreach ($this as $participant) {
			$nicks[] = $participant->flag . $participant->nick;
		}
		return $nicks;
	}	

	public function setTopic($msg) {
		$this->topic = $msg;
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
		if (!isset($this->nicknames[$target])) {
			return;
		}
		$participant = $this->nicknames[$target];
		$participant->mode = str_replace($mode, '', $participant->mode);
		$participant->onModeUpdate();
	}

	public function bind($event, $cb) {
		if (!isset($this->eventHandlers[$event])) {
			$this->eventHandlers[$event] = array();
		}
		$this->eventHandlers[$event][] = $cb;
	}

	public function unbind($event, $cb = null) {
		if (!isset($this->eventHandlers[$event])) {
			return false;
		}
		if ($cb === null) {
			unset($this->eventHandlers[$event]);
			return true;
		}
		if (($p = array_search($cb, $this->eventHandlers[$event], true)) === false) {
			return false;
		}
		unset($this->eventHandlers[$event][$p]);
		return true;
	}

	public function destroy() {
		unset($this->irc->channels[$this->name]);
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
class IRCClientChannelParticipant {
	public $channel;
	public $nick;
	public $user;
	public $flag;
	public $mode;
	public $unverified;
	public $host;

	public function setFlag($flag) {
		$flag = strtr($flag, array('H' => '', 'G' => '', '*' => ''));
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

	public function setUser($user) {
		$this->user = $user;
		return $this;
	}

	public function getUsermask() {
		return $this->nick . '!' . ($this->unverified ? '~' : '') . $this->user . '@' . $this->host;
	}

	public function setUnverified($bool) {
		$this->unverified = (bool) $bool;
		return $this;
	}

	public function setHost($host) {
		$this->host = $host;
		return $this;
	}

	public function setUsermask($mask) {
		if (is_string($mask)) {
			$mask = IRC::parseUsermask($mask);
		}
		$this
			->setUnverified($mask['unverified'])
			->setUser($mask['user'])
			->setNick($mask['nick'])
			->setHost($mask['host']);
		return $this;
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
			return $this;
		}
		$this->nick = $nick;
		unset($this->channel->nicknames[$this->nick]);
		$this->nick = $nick;
		$this->channel->nicknames[$this->nick] = $this;
		return $this;
	}

	public function destroy() {
		$this->channel->detach($this);
	}
	public function chanMessage($msg) {
		$this->channel->message($this->nick.': '.$msg);
	}
}

