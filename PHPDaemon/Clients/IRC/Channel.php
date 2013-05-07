<?php
namespace PHPDaemon\Clients\IRC;

use PHPDaemon\IRC;
use PHPDaemon\Structures\ObjectStorage;
use PHPDaemon\Traits\EventHandlers;

/**
 * @package    NetworkClients
 * @subpackage IRCClient
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */

class Channel extends ObjectStorage {
	use \PHPDaemon\Traits\EventHandlers;

	public $irc;
	public $name;
	public $nicknames = array();
	public $self;
	public $type;
	public $topic;

	public function __construct($irc, $name) {
		$this->irc  = $irc;
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
		}
		else {
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
		$participant       = $this->nicknames[$target];
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
