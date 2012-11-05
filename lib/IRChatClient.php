<?php

/**
 * @package NetworkClients
 * @subpackage IRCClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class IRChatClient extends NetworkClient {
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

}

class IRChatClientConnection extends NetworkClientConnection {
	public $user 			= 'Guest';     // Username
	public $password 		= '';         // Password
	public $EOL				= "\r\n";
	public $eventHandlers = array();
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
		251 => 'RPL_LUSERCLIENT',  252 => 'RPL_LUSEROP',
		253 => 'RPL_LUSERUNKNOWN',  254 => 'RPL_LUSERCHANNELS',
		255 => 'RPL_LUSERME',  256 => 'RPL_ADMINME',
		257 => 'RPL_ADMINLOC1',  258 => 'RPL_ADMINLOC2',
		259 => 'RPL_ADMINEMAIL',  261 => 'RPL_TRACELOG',
		262 => 'RPL_TRACEEND',  263 => 'RPL_TRYAGAIN',
		301 => 'RPL_AWAY',  302 => 'RPL_USERHOST',
		303 => 'RPL_ISON',  305 => 'RPL_UNAWAY',
		306 => 'RPL_NOWAWAY',  311 => 'RPL_WHOISUSER',
		312 => 'RPL_WHOISSERVER',  313 => 'RPL_WHOISOPERATOR',
  		314 => 'RPL_WHOWASUSER',  315 => 'RPL_ENDOFWHO',
  		317 => 'RPL_WHOISIDLE',  318 => 'RPL_ENDOFWHOIS',
  		319 => 'RPL_WHOISCHANNELS',  321 => 'RPL_LISTSTART',
  		322 => 'RPL_LIST',  323 => 'RPL_LISTEND',
  		324 => 'RPL_CHANNELMODEIS',  325 => 'RPL_UNIQOPIS',
  		331 => 'RPL_NOTOPIC',  332 => 'RPL_TOPIC',
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
		$this->command('USER '.$this->user.' 0 * :Real name');
		$this->command('NICK '.$this->path);
		$this->keepaliveTimer = setTimeout(function($timer) {
			$this->ping();
		}, 1e6 * 10);
	}

	public function command($cmd) {
		$this->writeln($cmd);
		Daemon::log('>>>>> '.$cmd);
	}

	public function join($channels) {
		$this->command('JOIN '.$channels);
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
		$this->command('PING phpdaemon');
	}

	public function message($to, $msg) {
		$this->command('PRIVMSG '.$to.' :'.$msg);
	}


	public function addEventHandler($event, $cb) {
		if (!isset($this->eventHandlers[$event])) {
			$this->eventHandlers[$event] = array();
		}
		$this->eventHandlers[$event][] = $cb;
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

	/**
	 * Called when new data received
	 * @param string New data
	 * @return void
	*/
	public function stdin($buf) {
		Timer::setTimeout($this->keepaliveTimer);
		$this->buf .= $buf;
		while (($line = $this->gets()) !== FALSE) {
			Daemon::log('<<<<< '.$line);
			list($from, $event, $body) = explode(' ', rtrim($line, "\r\n"), 3);
			if (substr($from, 0, 1) === ':') {
				$from = substr($from, 1);
			}
			$e = explode(' :', $body, 2);
			if (sizeof($e) == 2) {
				list ($target, $arg) = $e;
				if (substr($arg, 0, 1) === ':') {
					$arg = substr($arg, 1);
				}
			} else {
				$arg = $e[0];
				$target = null;
			}
			if (ctype_digit($event)) {
				$code = (int) $event;
				$event = isset(self::$codes[$code]) ? self::$codes[$code] : 'UNKNOWN-'.$code;
			}
			if ($event === 'RPL_WELCOME') {
				if ($this->onConnected) {
					$this->connected = true;
					$this->onConnected->executeAll($this);
					$this->onConnected = null;
				}
			}
			if ($event === 'RPL_MOTDSTART') {
				$this->motd = $arg;
				continue;
			}
			if ($event === 'RPL_MOTD') {
				$this->motd .= $arg;
				continue;
			}
			if ($event === 'RPL_ENDOFMOTD') {
				$this->motd .= $arg;
				$this->event('motd', $this->motd);
				continue;
			}
			if ($event === 'PRIVMSG') {
				$msg = array(
					'from' => $from,
					'to' => $target,
					'body' => $arg,
					'private' => substr($target, 0, 1) !== '#',
				);
				$this->event($msg['private'] ? 'privateMsg' : 'channelMsg', $msg);
			}
			//Daemon::log(Debug::dump([$from, $event, $target, $arg]));
		}
	}
}
