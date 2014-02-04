<?php
namespace PHPDaemon\Traits;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\FS\File;
use PHPDaemon\FS\FileSystem;

/**
 * Sessions
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */

trait Sessions {
	/**
	 * Session ID
	 * @var boolean
	 */
	protected $sessionId;

	/** @var int */
	protected $sessionStartTimeout = 10;

	/** @var bool */
	protected $sessionStarted = false;
	/** @var bool */
	protected $sessionFlushing = false;
	/** @var */
	protected $sessionFp;

	/**
	 * Is session started?
	 * @return bool
	 */
	public function sessionStarted() {
		return $this->sessionStarted;
	}

	/**
	 * Deferred event 'onSessionStart'
	 * @return callable
	 */
	public function onSessionStartEvent() {
		return function ($sessionStartEvent) {
			/** @var \PHPDaemon\Core\DeferredEvent $sessionStartEvent */
			$name = ini_get('session.name');
			$sid = $this->getCookieStr($name);
			if ($sid === '') {
				$this->sessionStartNew(function ($success) use ($sessionStartEvent) {
					$sessionStartEvent->setResult($success);
				});
				return;
			}
			$this->onSessionRead(function ($session) use ($sessionStartEvent) {
				if ($this->getSessionState() === null) {
					$this->sessionStartNew(function ($success) use ($sessionStartEvent) {
						$sessionStartEvent->setResult($success);
					});
					return;
				}
				$sessionStartEvent->setResult(true);
			});
		};
	}

	/**
	 * Deferred event 'onSessionRead'
	 * @return callable
	 */
	public function onSessionReadEvent() {

		return function ($sessionEvent) {
			/** @var \PHPDaemon\Core\DeferredEvent $sessionEvent */
			$name = ini_get('session.name');
			$sid  = $this->getCookieStr($name);
			if ($sid === '') {
				$sessionEvent->setResult(false);
				return;
			}
			if ($this->getSessionState() !== null) { //empty session is the session too
				$sessionEvent->setResult(true);
				return;
			}

			$this->sessionRead($sid, function ($data) use ($sessionEvent) {
				$canDecode = $data !== false && $this->sessionDecode($data);
				$sessionEvent->setResult($canDecode);
			});
		};
	}

	/**
	 * Reads session data
	 * @param $sid
	 * @param callable $cb
	 * @return void
	 */
	public function sessionRead($sid, $cb = null) {
		FileSystem::open(FileSystem::genRndTempnamPrefix(session_save_path(), 'sess_') . basename($sid), 'r+!', function ($fp) use ($cb) {
			if (!$fp) {
				call_user_func($cb, false);
				return;
			}
			$fp->readAll(function ($fp, $data) use ($cb) {
				$this->sessionFp = $fp;
				call_user_func($cb, $data);
			});
		});
	}

	/**
	 * Commmit session data
	 * @param callable $cb
	 * @return void
	 */
	public function sessionCommit($cb = null) {
		if (!$this->sessionFp || $this->sessionFlushing) {
			if ($cb) {
				call_user_func($cb, false);
			}
			return;
		}
		$this->sessionFlushing = true;
		$data                  = $this->sessionEncode();
		$l                     = strlen($data);
		$this->sessionFp->write($data, function ($file, $result) use ($l, $cb) {
			$file->truncate($l, function ($file, $result) use ($cb) {
				$this->sessionFlushing = false;
				if ($cb) {
					call_user_func($cb, true);
				}
			});
		});
	}

	/**
	 * Session start
	 * @param bool $force_start = true
	 * @return void
	 */
	protected function sessionStart($force_start = true) {
		if ($this->sessionStarted) {
			return;
		}
		$this->sessionStarted = true;
		if (!$this instanceof \PHPDaemon\HTTPRequest\Generic) {
			Daemon::log('Called ' . get_class($this). '(trait \PHPDaemon\Traits\Sessions)->sessionStart() outside of Request. You should use onSessionStart.');
			return;
		}
		$f = true; // hack to avoid a sort of "race condition"
		$this->onSessionStart(function ($event) use (&$f) {
			$f = false;
			$this->wakeup();
		});
		if ($f) {
			$this->sleep($this->sessionStartTimeout);
		}
	}

	/**
	 * Start new session
	 * @param callable $cb
	 */
	protected function sessionStartNew($cb = null) {
		FileSystem::tempnam(session_save_path(), 'php', function ($fp) use ($cb) {
			if (!$fp) {
				call_user_func($cb, false);
				return;
			}
			$this->sessionFp = $fp;
			$this->sessionId = substr(basename($fp->path), 3);
			$this->setcookie(
				ini_get('session.name')
				, $this->sessionId
				, ini_get('session.cookie_lifetime')
				, ini_get('session.cookie_path')
				, ini_get('session.cookie_domain')
				, ini_get('session.cookie_secure')
				, ini_get('session.cookie_httponly')
			);
			call_user_func($cb, true);
		});
	}

	/**
	 * Encodes session data
	 * @return bool|string
	 */
	protected function sessionEncode() {
		$type = ini_get('session.serialize_handler');
		if ($type === 'php') {
			return $this->serialize_php($this->getSessionState());
		}
		if ($type === 'php_binary') {
			return igbinary_serialize($this->getSessionState());
		}
		return false;
	}

	/**
	 * Decodes session data
	 * @param $str
	 * @return bool
	 */
	protected function sessionDecode($str) {
		$type = ini_get('session.serialize_handler');
		if ($type === 'php') {
			$this->setSessionState($this->unserialize_php($str));
			return true;
		}
		if ($type === 'php_binary') {
			$this->setSessionState(igbinary_unserialize($str));
			return true;
		}
		return false;
	}

    /**
     * session_encode() - clone, which not require session_start()
     *
     * @see http://www.php.net/manual/en/function.session-encode.php
     * @param $array
     * @param bool $safe
     *
     * @return string
     */
    public function serialize_php($array, $safe = true)
    {
        // the session is passed as refernece, even if you dont want it to
        if ($safe) {
            $array = unserialize(serialize($array));
        }
        $raw = '';
        $line = 0;
        $keys = array_keys($array);
        foreach ($keys as $key) {
            $value = $array[$key];
            $line++;
            $raw .= $key . '|';
            if (is_array($value) && isset($value['huge_recursion_blocker_we_hope'])) {
                $raw .= 'R:' . $value['huge_recursion_blocker_we_hope'] . ';';
            } else {
                $raw .= serialize($value);
            }
            $array[$key] = array('huge_recursion_blocker_we_hope' => $line);
        }

        return $raw;
    }

    /**
     * session_decode() - clone, which not require session_start()
     *
     * @see http://www.php.net/manual/en/function.session-decode.php#108037
     * @param $session_data
     *
     * @return array
     * @throws \Exception
     */
    private function unserialize_php($session_data)
    {
        $return_data = array();
        $offset = 0;
        while ($offset < strlen($session_data)) {
            if (!strstr(substr($session_data, $offset), "|")) {
                throw new \Exception("invalid session data, remaining: " . substr($session_data, $offset));
            }
            $pos = strpos($session_data, "|", $offset);
            $num = $pos - $offset;
            $varname = substr($session_data, $offset, $num);
            $offset += $num + 1;
            $data = unserialize(substr($session_data, $offset));
            $return_data[$varname] = $data;
            $offset += strlen(serialize($data));
        }

        return $return_data;
    }
}
