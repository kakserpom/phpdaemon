<?php
namespace PHPDaemon\Clients\IRC;

use PHPDaemon\Structures\ObjectStorage;
use PHPDaemon\Traits\EventHandlers;
use PHPDaemon\Utils\IRC;

/**
 * @package    NetworkClients
 * @subpackage IRCClient
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Channel extends ObjectStorage {
	use \PHPDaemon\Traits\EventHandlers;

	/**
	 * @var Connection
	 */
	public $irc;
	
	/**
	 * @var string
	 */
	public $name;
	
	/**
	 * @var array
	 */
	public $nicknames = [];
	
	/**
	 * @var
	 */
	public $self;
	
	/**
	 * @var string
	 */
	public $type;
	
	/**
	 * @var string
	 */
	public $topic;

	/**
	 * @param Connection $irc
	 * @param string     $name
	 */
	public function __construct($irc, $name) {
		$this->irc  = $irc;
		$this->name = $name;
	}

	/**
	 * @TODO DESCR
	 */
	public function who() {
		$this->irc->command('WHO', $this->name);
	}

	/**
	 * @TODO DESCR
	 * @param array|string $mask
	 * @param mixed        $msg
	 */
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

	/**
	 * @TODO DESCR
	 * @param string $type
	 */
	public function setChanType($type) {
		$this->type = $type;
	}

	/**
	 * @TODO DESCR
	 * @return array
	 */
	public function exportNicksArray() {
		$nicks = [];
		foreach ($this as $participant) {
			$nicks[] = $participant->flag . $participant->nick;
		}
		return $nicks;
	}

	/**
	 * @TODO DESCR
	 * @param string $msg
	 */
	public function setTopic($msg) {
		$this->topic = $msg;
	}

	/**
	 * @TODO DESCR
	 * @param string $nick
	 * @param string $mode
	 */
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

	/**
	 * @TODO DESCR
	 * @param string $target
	 * @param string $mode
	 */
	public function removeMode($target, $mode) {
		if (!isset($this->nicknames[$target])) {
			return;
		}
		$participant       = $this->nicknames[$target];
		$participant->mode = str_replace($mode, '', $participant->mode);
		$participant->onModeUpdate();
	}

	/**
	 * @TODO DESCR
	 */
	public function destroy() {
		unset($this->irc->channels[$this->name]);
	}

	/**
	 * @TODO DESCR
	 */
	public function join() {
		$this->irc->join($this->name);
	}

	/**
	 * @TODO DESCR
	 * @param mixed $msg
	 */
	public function part($msg = null) {
		$this->irc->part($this->name, $msg);
	}

	/**
	 * @TODO DESCR
	 * @param  string $type
	 * @return $this
	 */
	public function setType($type) {
		$this->type = $type;
		return $this;
	}

	/**
	 * @TODO DESCR
	 * @param object $obj
	 */
	public function detach($obj) {
		parent::detach($obj);
		unset($this->nicknames[$obj->nick]);
	}
}
