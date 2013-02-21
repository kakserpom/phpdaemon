<?php

/**
 * @package NetworkClients
 * @subpackage IRCClient
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

class IRCClientChannel extends ObjectStorage {
	use EventHandlers;

	public $irc;
	public $name;
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

	public function destroy() {
		unset($this->irc->channels[$this->name]);
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
		return new static($channel, $nick);
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

