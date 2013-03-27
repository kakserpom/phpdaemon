<?php

/**
 * @package NetworkServers
 * @subpackage IRCBouncer
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class IRCBouncer extends NetworkServer {
	public $client;
	public $conn;
	public $protologging = false;
	public $db;
	public $messages;
	public $channels;

	protected function init() {
		$this->client = IRCClient::getInstance();
		$this->client->protologging = $this->protologging;
		$this->db = MongoClientAsync::getInstance();
		$this->messages = $this->db->{$this->config->dbname->value . '.messages'};
		$this->channels = $this->db->{$this->config->dbname->value . '.channels'};
	}

	/**
	 * Setting default config options
	 * Overriden from ConnectionPool::getConfigDefaults
	 * @return array|false
	 */
	protected function getConfigDefaults() {
		return [
			// @todo add description strings
			'listen'				=> '0.0.0.0',
			'port' 			        => 6667,
			'url' => 'irc://user@host/nickname/realname',
			'servername' => 'bnchost.tld',
			'defaultchannels' => '',
			'protologging' => 0,
			'dbname' => 'bnc',
			'password' => 'SecretPwd',
		];
	}

	public function applyConfig() {
		parent::applyConfig();
		$this->protologging = (bool) $this->config->protologging->value;
		if (isset($this->client)) {
			$this->client->protologging = $this->protologging;
		}
	}

	public function onReady() {
		parent::onReady();
		$this->client->onReady();
		$this->getConnection($this->config->url->value);
	}

	public function getConnection($url) {
		$this->client->getConnection($url, function ($conn) use ($url) {
			$this->conn = $conn;
			$conn->attachedClients = new ObjectStorage;
			if ($conn->connected) {
				Daemon::log('IRC bot connected at '.$url);
				$conn->join($this->config->defaultchannels->value);
				$conn->bind('motd', function($conn) {
					//Daemon::log($conn->motd);
				});
				foreach ($this as $bouncerConn) {
					if (!$bouncerConn->attachedServer) {
						$bouncerConn->attachTo($conn);
					}
				}
				$conn->bind('command', function($conn, $from, $cmd, $args) {
					if ($cmd === 'PONG') {
						return;
					}
					elseif ($cmd === 'PING') {
						return;
					}
					if ($from['orig'] === $conn->servername) {
						$from['orig'] = $this->config->servername->value;
					}
					$conn->attachedClients->each('commandArr', $from['orig'], $cmd, $args);
				});
				$conn->bind('privateMsg', function($conn, $msg) {
					Daemon::log('IRCBouncer: got private message \''.$msg['body'].'\' from \''.$msg['from']['orig'].'\'');
				});
				$conn->bind('msg', function($conn, $msg) {
					$msg['ts'] = microtime(true);
					$msg['dir'] = 'i';
					$this->messages->insert($msg);
				});
				$conn->bind('disconnect', function($conn) use ($url) {
					foreach ($this as $bouncerConn) {
						if ($bouncerConn->attachedServer === $conn) {
							$bouncerConn->detach();
						}
					}
					$this->getConnection($url);
				});
			}
			else {
				Daemon::log('IRCBouncer: unable to connect ('.$url.')');
			}
		});
	}
}

class IRCBouncerConnection extends Connection {
	use EventHandlers;

	public $EOL = "\r\n";
	public $attachedServer;
	public $usermask;
	public $latency;
	public $lastPingTS;
	public $timeout = 180;
	public $protologging = false;

	/**
	 * Called when the connection is handshaked (at low-level), and peer is ready to recv. data
	 * @return void
	 */
	public function onReady() {
		$conn = $this;
		$this->keepaliveTimer = setTimeout(function($timer) use ($conn) {
			$conn->ping();
		}, 10e6);
	}

	public function onFinish() {
		if ($this->attachedServer) {
			$this->attachedServer->attachedClients->detach($this);
		}
		Timer::remove($this->keepaliveTimer);
		parent::onFinish();
	}

	public function ping() {
		$this->lastPingTS = microtime(true);
		$this->writeln('PING :'.$this->usermask);
		Timer::setTimeout($this->keepaliveTimer);
	}

	public function command($from, $cmd) {
		if ($from === null) {
			$from = $this->pool->config->servername->value;
		}
		$cmd = IRC::getCodeByCommand($cmd);
		$line = ':' . $from . ' ' . $cmd;
		for ($i = 2, $s = func_num_args(); $i < $s; ++$i) {
			$arg = func_get_arg($i);
			if (($i + 1 === $s) && (strpos($arg, "\x20") !== false)) {
				$line .= ' :';
			} else {
				$line .= ' ';
			}
			$line .= $arg;
		}
		$this->writeln($line);
		if ($this->pool->protologging && !in_array($cmd, ['PONG'])) {
			Daemon::log('=>=>=>=> '.json_encode($line));
		}
	}

	public function commandArr($from, $cmd, $args) {
		if ($from === null) {
			$from = $this->pool->config->servername->value;
		}
		if (is_string($args)) {
			Daemon::log(get_class($this).'->commandArr: args is string');
			return;
		}
		$cmd = IRC::getCodeByCommand($cmd);
		$line = ':' . $from . ' ' . $cmd;
		for ($i = 0, $s = sizeof($args); $i < $s; ++$i) {
			if (($i + 1 === $s) && (strpos($args[$i], "\x20") !== false)) {
				$line .= ' :';
			} else {
				$line .= ' ';
			}
			$line .= $args[$i];
		}
		$this->writeln($line);
		if ($this->pool->protologging && !in_array($cmd, ['PONG'])) {
			Daemon::log('=>=>=>=> '.json_encode($line));
		}
	}

	public function detach() {
		if ($this->attachedServer) {
			$this->attachedServer->attachedClients->detach($this);
			$this->attachedServer = null;
		}
	}

	public function attachTo() {
		if ($this->pool->conn) {
			$this->attachedServer = $this->pool->conn;
			$this->attachedServer->attachedClients->attach($this);
		} else {
			return;
		}
		$this->msgFromBNC('Attached to '.$this->attachedServer->url);
		$this->usermask = $this->attachedServer->nick . '!' . $this->attachedServer->user . '@' . $this->pool->config->servername->value;
		$this->command(null, 'RPL_WELCOME', $this->attachedServer->nick, 'Welcome to phpDaemon bouncer -- ' . $this->pool->config->servername->value);
		foreach ($this->attachedServer->channels as $chan) {
			$this->exportChannel($chan);
		}
	}

	public function exportChannel($chan) {
		$this->command($this->usermask, 'JOIN', $chan->name);
		$this->command($this->usermask, 'RPL_TOPIC', $chan->irc->nick, $chan->name, $chan->topic);
		$names = $chan->exportNicksArray();
		$packet = '';
		$maxlen = 510 - 7 - strlen($this->pool->config->servername->value) - $chan->irc->nick - 1;
		for ($i = 0, $s = sizeof($names); $i < $s; ++$i) {
			$packet .= ($packet !== '' ? ' ' : '') . $names[$i];
			if (!isset($names[$i + 1]) || (strlen($packet) + strlen($names[$i + 1]) + 1 > $maxlen)) {
				$this->command(null, 'RPL_NAMREPLY', $chan->irc->nick, $chan->type, $chan->name, $packet);
				$packet = '';
			}
		}
		$this->command(null, 'RPL_ENDOFNAMES', $chan->irc->nick, $chan->name, 'End of /NAMES list');
	}

	public function onCommand($cmd, $args) {
		if ($cmd === 'USER') {
			//list ($nick) = $args;
			$this->attachTo();
			return;
		}
		elseif ($cmd === 'QUIT') {
			$this->finish();
			return;
		}
		elseif ($cmd === 'PING') {
			$this->writeln(isset($args[0]) ? 'PONG :' . $args[0] : 'PONG');
			return;
		}
		elseif ($cmd === 'PONG') {
			if ($this->lastPingTS) {
				$this->latency = microtime(true) - $this->lastPingTS;
				$this->lastPingTS = null;
				$this->event('lantency');
			}
			return;
		}
		elseif ($cmd === 'NICK') {
			return;
		}
		elseif ($cmd === 'PRIVMSG') {
			list ($target, $msg) = $args;
			if ($target === '$') {
				if (preg_match('~^\s*(NICK\s+\S+|DETACH|ATTACH|BYE)\s*$~i', $msg, $m)) {
					$clientCmd = strtoupper($m[1]);
					if ($clientCmd === 'NICK') {

					}
					elseif ($clientCmd === 'DETACH') {
						$this->detach();
						$this->msgFromBNC('Detached.');
					}
					elseif ($clientCmd === 'ATTACH') {
						$this->attachTo();
					}
					elseif ($clientCmd === 'BYE') {
						$this->detach();
						$this->msgFromBNC('Bye-bye.');
						$this->finish();
					}

				} else {
					$this->msgFromBNC('Unknown command: '.$msg);
				}
				return;
			}
			$this->pool->messages->insert([
				'from' => $this->usermask,
				'to' => $target,
				'body' => $msg,
				'ts' => microtime(true),
				'dir' => 'o',
			]);
		}
		if ($this->attachedServer) {
			$this->attachedServer->commandArr($cmd, $args);
		}
		if ($this->protologging) {
			Daemon::$process->log('<=<=<=< '.$cmd.': '.json_encode($args));
		}
	}

	public function msgFromBNC($msg) {
		if ($this->usermask === null) {
			return;
		}
		$this->command('$!@' . $this->pool->config->servername->value, 'PRIVMSG' , $this->usermask, $msg);
	}

	/**
	 * Called when new data received
	 * @return void
	*/
	public function onRead() {
		Timer::setTimeout($this->keepaliveTimer);
		while (($line = $this->readline()) !== null) {
			if ($line === '') {
				continue;
			}
			if (strlen($line) > 512) {
				Daemon::$process->log('IRCBouncerConnection error: buffer overflow.');
				$this->finish();
				return;
			}
			$line = binarySubstr($line, 0, -strlen($this->EOL));
			$p = strpos($line, ':', 1);
			$max = $p ? substr_count($line, "\x20", 0, $p) + 1 : 18;
			$e = explode("\x20", $line, $max);
			$i = 0;
			$cmd = $e[$i++];
			$args = [];

			for ($s = min(sizeof($e), 14); $i < $s; ++$i) {
				if ($e[$i][0] === ':') {
					$args[] = binarySubstr($e[$i], 1);
					break;
				}
				$args[] = $e[$i];
			}

			if (ctype_digit($cmd)) {
				$code = (int) $cmd;
				$cmd = isset(IRC::$codes[$code]) ? IRC::$codes[$code] : 'UNKNOWN-'.$code;
			}
			$this->onCommand($cmd, $args);
		}
		if (strlen($this->buf) > 512) {
			Daemon::$process->log('IRCClientConnection error: buffer overflow.');
			$this->finish();
		}
	}

}
