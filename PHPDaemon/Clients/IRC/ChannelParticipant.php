<?php
namespace PHPDaemon\Clients\IRC;

use PHPDaemon\Utils\IRC;

class ChannelParticipant {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * @var
	 */
	public $channel;
	/**
	 * @var
	 */
	public $nick;
	/**
	 * @var
	 */
	public $user;
	/**
	 * @var
	 */
	public $flag;
	/**
	 * @var
	 */
	public $mode;
	/**
	 * @var
	 */
	public $unverified;
	/**
	 * @var
	 */
	public $host;

	/**
	 * @param $flag
	 * @return $this
	 */
	public function setFlag($flag) {
		$flag       = strtr($flag, ['H' => '', 'G' => '', '*' => '']);
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

	/**
	 * @param $channel
	 * @param $nick
	 */
	public function __construct($channel, $nick) {
		$this->channel = $channel;
		$this->setNick($nick);
		$this->channel->attach($this);
	}

	/**
	 * @TODO DESCR
	 */
	public function onModeUpdate() {
		if (strpos($this->mode, 'o') !== false) {
			$this->flag = '@';
		}
		elseif (strpos($this->mode, 'h') !== false) {
			$this->flag = '%';
		}
		elseif (strpos($this->mode, 'v') !== false) {
			$this->flag = '+';
		}
		else {
			$this->flag = '';
		}
	}

	/**
	 * @TODO DESCR
	 * @param $user
	 * @return $this
	 */
	public function setUser($user) {
		$this->user = $user;
		return $this;
	}

	/**
	 * @TODO DESCR
	 * @return string
	 */
	public function getUsermask() {
		return $this->nick . '!' . ($this->unverified ? '~' : '') . $this->user . '@' . $this->host;
	}

	/**
	 * @TODO DESCR
	 * @param $bool
	 * @return $this
	 */
	public function setUnverified($bool) {
		$this->unverified = (bool)$bool;
		return $this;
	}

	/**
	 * @TODO DESCR
	 * @param $host
	 * @return $this
	 */
	public function setHost($host) {
		$this->host = $host;
		return $this;
	}

	/**
	 * @TODO DESCR
	 * @param $mask
	 * @return $this
	 */
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

	/**
	 * @TODO DESCR
	 * @param $channel
	 * @param $nick
	 * @return static
	 */
	public static function instance($channel, $nick) {
		if (isset($channel->nicknames[$nick])) {
			return $channel->nicknames[$nick];
		}
		return new static($channel, $nick);
	}

	/**
	 * @TODO DESCR
	 * @param $nick
	 * @return $this
	 */
	public function setNick($nick) {
		if ($this->nick === $nick) {
			return $this;
		}
		$this->nick = $nick;
		unset($this->channel->nicknames[$this->nick]);
		$this->nick                            = $nick;
		$this->channel->nicknames[$this->nick] = $this;
		return $this;
	}

	/**
	 * @TODO DESCR
	 */
	public function destroy() {
		$this->channel->detach($this);
	}

	/**
	 * @TODO DESCR
	 * @param $msg
	 */
	public function chanMessage($msg) {
		$this->channel->message($this->nick . ': ' . $msg);
	}
}
