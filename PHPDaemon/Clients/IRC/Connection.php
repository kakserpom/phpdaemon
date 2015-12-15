<?php
namespace PHPDaemon\Clients\IRC;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Utils\IRC;

/**
 * @package    NetworkClients
 * @subpackage IRCClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Connection extends ClientConnection {

	/**
	 * @var string Username
	 */
	protected $user = 'Guest';
	
	/**
	 * @var string Password
	 */
	protected $password = '';
	
	/**
	 * @var string
	 */
	public $EOL = "\r\n";
	
	/**
	 * @var string
	 */
	public $nick;
	
	/**
	 * @var string
	 */
	public $realname;
	
	/**
	 * @var string
	 */
	public $mode = '';
	
	/**
	 * @var array
	 */
	public $buffers = [];
	
	/**
	 * @var string
	 */
	public $servername;
	
	/**
	 * @var array
	 */
	public $channels = [];
	
	/**
	 * @var integer
	 */
	public $latency;
	
	/**
	 * @var integer
	 */
	public $lastPingTS;
	
	/**
	 * @var int
	 */
	public $timeout = 300;
	
	/**
	 * @var bool To get local port number
	 */
	protected $bevConnectEnabled = false;

	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		if ($this->pool->identd) {
			/** @noinspection PhpParamsInspection */
			$this->getSocketName();
			$this->pool->identd->registerPair($this->locAddr, $this->locPort, ['UNIX', $this->user]);
		}
		list($this->nick, $this->realname) = explode('/', $this->path . '/John Doe');
		$this->command('USER', $this->user, 0, '*', $this->realname);
		$this->command('NICK', $this->nick);
		if (strlen($this->password)) {
			$this->message('NickServ', 'IDENTIFY ' . $this->password);
		}
	}

	/**
	 * @TODO DESCR
	 * @param  string $cmd
	 * @param  mixed  ...$args Arguments
	 */
	public function command($cmd) {
		if (ctype_digit($cmd)) {
			$cmd = IRC::getCommandByCode((int)$cmd);
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
			Daemon::log('->->->-> ' . $line);
		}
	}

	/**
	 * @TODO DESCR
	 * @param  integer|string $cmd
	 * @param  array $args
	 * @return bool
	 */
	public function commandArr($cmd, $args = []) {
		if (!is_array($args)) {
			return false;
		}
		if (ctype_digit($cmd)) {
			$cmd = IRC::getCommandByCode((int)$cmd);
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
		if ($this->pool->protologging && $cmd !== 'PONG') {
			Daemon::log('->->->-> ' . $line);
		}
		return true;
	}

	/**
	 * @TODO DESCR
	 * @param array $channels
	 */
	public function join($channels) {
		if (!is_array($channels)) {
			$channels = array_map('trim', explode(',', $channels));
		}
		foreach ($channels as $chan) {
			$this->command('JOIN', $chan);
		}
	}

	/**
	 * @TODO DESCR
	 * @param array $channels
	 * @param mixed $msg
	 */
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

	/**
	 * @TODO DESCR
	 */
	public function ping() {
		$this->lastPingTS = microtime(true);
		$this->writeln('PING :' . $this->servername);
	}

	/**
	 * @TODO DESCR
	 * @param string $to
	 * @param string $msg
	 */
	public function message($to, $msg) {
		$this->command('PRIVMSG', $to, $msg);
	}

	/**
	 * @TODO DESCR
	 * @param string $channel
	 * @param string $target
	 * @param string $mode
	 */
	public function addMode($channel, $target, $mode) {
		if ($channel) {
			$this->channel($channel)->addMode($target, $mode);
		}
		else {
			if (strpos($this->mode, $mode) === false) {
				$this->mode .= $mode;
			}
		}
	}

	/**
	 * @TODO DESCR
	 * @param string $channel
	 * @param string $target
	 * @param string $mode
	 */
	public function removeMode($channel, $target, $mode) {
		if ($channel) {
			$this->channel($channel)->removeMode($target, $mode);
		}
		$this->mode = str_replace($mode, '', $this->mode);
	}

	/**
	 * @TODO DESCR
	 * @param string $from
	 * @param string $cmd
	 * @param array $args
	 */
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
			$this->event('notice', $target, $text);
		}
		elseif ($cmd === 'RPL_YOURHOST') {
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
			$this->motd .= "\r\n"; // . $args[1];
			$this->event('motd', $this->motd);
			return;
		}
		elseif ($cmd === 'JOIN') {
			list ($channel) = $args;
			$chan = $this->channel($channel);
			ChannelParticipant::instance($chan, $from['nick'])->setUsermask($from);
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
			list( /*$myNick*/, $chanType, $channelName) = $args;
			$this->channel($channelName)->setChanType($chanType);
			if (!isset($this->buffers[$bufName])) {
				$this->buffers[$bufName] = [];
			}
			if (!isset($this->buffers[$bufName][$channelName])) {
				$this->buffers[$bufName][$channelName] = new \SplStack;
			}
			$this->buffers[$bufName][$channelName]->push($args);
		}
		elseif ($cmd === 'RPL_ENDOFNAMES') {
			$bufName = 'RPL_NAMREPLY';
			list( /*$nick*/, $channelName, /*$text*/) = $args;
			if (!isset($this->buffers[$bufName][$channelName])) {
				return;
			}
			$buf  = $this->buffers[$bufName][$channelName];
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
					ChannelParticipant::instance($chan, $nickname)->setFlag($flag);
				}

			}
		}
		elseif ($cmd === 'RPL_WHOREPLY') {
			if (sizeof($args) < 7) {

			}
			list( /*$myNick*/, $channelName, $user, /*$host*/, $server, $nick, $mode, $hopCountRealName) = $args;
			list ($hopCount, $realName) = explode("\x20", $hopCountRealName);
			if ($channel = $this->channelIfExists($channelName)) {
				ChannelParticipant::instance($channel, $nick)
						->setUsermask($nick . '!' . $user . '@' . $server)
						->setFlag($mode);
			}
		}
		elseif ($cmd === 'RPL_TOPIC') {
			list( /*$myNick*/, $channelName, $text) = $args;
			if ($channel = $this->channelIfExists($channelName)) {
				$channel->setTopic($text);
			}
		}
		elseif ($cmd === 'RPL_ENDOFWHO') {
			/*list($myNick, $channelName, $text) = $args;*/
		}
		elseif ($cmd === 'MODE') {
			if (sizeof($args) === 3) {
				list ($channel, $mode, $target) = $args;
			}
			else {
				$channel = null;
				list ($target, $mode) = $args;
			}
			if (strlen($mode)) {
				if ($mode[0] === '+') {
					$this->addMode($channel, $target, binarySubstr($mode, 1));
				}
				elseif ($mode[0] === '-') {
					$this->removeMode($channel, $target, binarySubstr($mode, 1));
				}
			}
		}
		elseif ($cmd === 'RPL_CREATED') {
			list( /*$to*/, $this->created) = $args;
		}
		elseif ($cmd === 'RPL_MYINFO') {
			list( /*$to*/, $this->servername, $this->serverver, $this->availUserModes, $this->availChanModes) = $args;
		}
		elseif ($cmd === 'PRIVMSG') {
			list ($target, $body) = $args;
			$msg = [
				'from'    => $from,
				'to'      => $target,
				'body'    => $body,
				'private' => substr($target, 0, 1) !== '#',
			];
			$this->event($msg['private'] ? 'privateMsg' : 'channelMsg', $msg);
			$this->event('msg', $msg);
			if (!$msg['private']) {
				$this->channel($target)->event('msg', $msg);
			}
		}
		elseif ($cmd === 'PING') {
			$this->writeln(isset($args[0]) ? 'PONG :' . $args[0] : 'PONG');
		}
		elseif ($cmd === 'PONG') {
			if ($this->lastPingTS) {
				$this->latency    = microtime(true) - $this->lastPingTS;
				$this->lastPingTS = null;
			}
			return;
		}
		if ($this->pool->protologging) {
			Daemon::$process->log('<-<-<-< ' . $cmd . ': ' . json_encode($args) . ' (' . json_encode($from['orig']) . ') (' . json_encode($this->lastLine) . ')');
		}
	}

	/**
	 * Called when new data received
	 * @return void
	 */
	public function onRead() {
		while (($line = $this->readline()) !== null) {
			if ($line === '') {
				continue;
			}
			if (strlen($line) > 512) {
				Daemon::$process->log('IRCClientConnection error: buffer overflow.');
				$this->finish();
				return;
			}
			$line = binarySubstr($line, 0, -strlen($this->EOL));
			$p    = strpos($line, ' :', 1);
			$max  = $p !== false ? substr_count($line, "\x20", 0, $p + 1) + 1 : 18;
			$e    = explode("\x20", $line, $max);
			$i    = 0;
			$from = IRC::parseUsermask($e[$i]{0} === ':' ? binarySubstr($e[$i++], 1) : null);
			$cmd  = $e[$i++];
			$args = [];

			for ($s = min(sizeof($e), 14); $i < $s; ++$i) {
				if ($e[$i][0] === ':') {
					$args[] = binarySubstr($e[$i], 1);
					break;
				}
				$args[] = $e[$i];
			}

			if (ctype_digit($cmd)) {
				$code = (int)$cmd;
				$cmd  = isset(IRC::$codes[$code]) ? IRC::$codes[$code] : $code;
			}
			$this->lastLine = $line;
			$this->onCommand($from, $cmd, $args);
		}
		if (strlen($this->buf) > 512) {
			Daemon::$process->log('IRCClientConnection error: buffer overflow.');
			$this->finish();
		}
	}

	/**
	 * @TODO DESCR
	 * @param  string $chan
	 * @return Channel
	 */
	public function channel($chan) {
		if (isset($this->channels[$chan])) {
			return $this->channels[$chan];
		}
		return $this->channels[$chan] = new Channel($this, $chan);
	}

	/**
	 * @TODO DESCR
	 * @param  string $chan
	 * @return bool
	 */
	public function channelIfExists($chan) {
		if (isset($this->channels[$chan])) {
			return $this->channels[$chan];
		}
		return false;
	}
}
