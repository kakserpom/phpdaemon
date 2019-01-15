<?php
namespace PHPDaemon\Traits;

use PHPDaemon\Core\CallbackWrapper;
use PHPDaemon\Core\Daemon;
use PHPDaemon\FS\FileSystem;

/**
 * Sessions
 * @package PHPDaemon\Traits
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
trait Sessions
{
    /**
     * @var string Session ID
     */
    protected $sessionId;

    /**
     * @var integer
     */
    protected $sessionStartTimeout = 10;

    /**
     * @var boolean
     */
    protected $sessionStarted = false;

    /**
     * @var boolean
     */
    protected $sessionFlushing = false;

    /**
     * @var resource
     */
    protected $sessionFp;

    /**
     * @var string
     */
    protected $sessionPrefix = 'sess_';

    /**
     * Is session started?
     * @return boolean
     */
    public function sessionStarted()
    {
        return $this->sessionStarted;
    }

    /**
     * Deferred event 'onSessionStart'
     * @return callable
     */
    public function onSessionStartEvent()
    {
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
    public function onSessionReadEvent()
    {
        return function ($sessionEvent) {
            /** @var \PHPDaemon\Core\DeferredEvent $sessionEvent */
            $name = ini_get('session.name');
            $sid = $this->getCookieStr($name);
            if ($sid === '') {
                $sessionEvent->setResult(false);
                return;
            }
            if ($this->getSessionState() !== null) {
                $sessionEvent->setResult(true);
                return;
            }
            $this->sessionRead($sid, function ($data) use ($sessionEvent, $sid) {
                $canDecode = $data !== false && $this->sessionDecode($data);
                $sessionEvent->setResult($canDecode);
            });
        };
    }

    /**
     * Reads session data
     * @param  string $sid Session ID
     * @param  callable $cb Callback
     * @return void
     */
    public function sessionRead($sid, $cb = null)
    {
        FileSystem::open(FileSystem::genRndTempnamPrefix(session_save_path(), $this->sessionPrefix) . basename($sid),
            'r+!', function ($fp) use ($cb) {
                if (!$fp) {
                    $cb(false);
                    return;
                }
                $fp->readAll(function ($fp, $data) use ($cb) {
                    $this->sessionFp = $fp;
                    $cb($data);
                });
            });
    }

    /**
     * Commmit session data
     * @param  callable $cb Callback
     * @return void
     */
    public function sessionCommit($cb = null)
    {
        if (!$this->sessionFp || $this->sessionFlushing) {
            if ($cb) {
                $cb(false);
            }
            return;
        }
        $this->sessionFlushing = true;
        $data = $this->sessionEncode();
        $l = mb_orig_strlen($data);
        $cb = CallbackWrapper::wrap($cb);
        $this->sessionFp->write($data, function ($file, $result) use ($l, $cb) {
            $file->truncate($l, function ($file, $result) use ($cb) {
                $this->sessionFlushing = false;
                if ($cb) {
                    $cb(true);
                }
            });
        });
    }

    /**
     * Session start
     * @param  boolean $force_start
     * @return void
     */
    protected function sessionStart($force_start = true)
    {
        if ($this->sessionStarted) {
            return;
        }
        $this->sessionStarted = true;
        if (!$this instanceof \PHPDaemon\HTTPRequest\Generic) {
            Daemon::log('Called ' . get_class($this) . '(trait \PHPDaemon\Traits\Sessions)->sessionStart() outside of Request. You should use onSessionStart.');
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
     * @param  callable $cb Callback
     * @return void
     */
    protected function sessionStartNew($cb = null)
    {
        FileSystem::tempnam(session_save_path(), $this->sessionPrefix, function ($fp) use ($cb) {
            if (!$fp) {
                $cb(false);
                return;
            }

            $this->sessionFp = $fp;
            $this->sessionId = substr(basename($fp->path), mb_orig_strlen($this->sessionPrefix));
            $this->setcookie(
                ini_get('session.name'),
                $this->sessionId,
                ini_get('session.cookie_lifetime'),
                ini_get('session.cookie_path'),
                ini_get('session.cookie_domain'),
                ini_get('session.cookie_secure'),
                ini_get('session.cookie_httponly')
            );

            $cb(true);
        });
    }

    /**
     * Encodes session data
     * @return string|false
     */
    protected function sessionEncode()
    {
        $type = ini_get('session.serialize_handler');
        if ($type === 'php') {
            return $this->serializePHP($this->getSessionState());
        }
        if ($type === 'php_binary') {
            return igbinary_serialize($this->getSessionState());
        }
        return false;
    }

    /**
     * Set session state
     * @param mixed $var
     * @return void
     */
    protected function setSessionState($var)
    {
        $this->attrs->session = $var;
    }

    /**
     * Get session state
     * @return mixed
     */
    protected function getSessionState()
    {
        return $this->attrs->session;
    }

    /**
     * Decodes session data
     * @param  string $str Data
     * @return boolean
     */
    protected function sessionDecode($str)
    {
        $type = ini_get('session.serialize_handler');
        if ($type === 'php') {
            $this->setSessionState($this->unserializePHP($str));
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
     * @see    http://www.php.net/manual/en/function.session-encode.php
     * @param  array $array
     * @return string
     */
    public function serializePHP($array)
    {
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
     * @see    http://www.php.net/manual/en/function.session-decode.php#108037
     * @param  string $session_data
     * @return array
     */
    protected function unserializePHP($session_data)
    {
        $return_data = array();
        $offset = 0;

        while ($offset < mb_orig_strlen($session_data)) {
            if (!strstr(substr($session_data, $offset), "|")) {
                return $return_data;
                //throw new \Exception("invalid session data, remaining: " . substr($session_data, $offset));
            }
            $pos = mb_orig_strpos($session_data, "|", $offset);
            $num = $pos - $offset;
            $varname = substr($session_data, $offset, $num);
            $offset += $num + 1;
            $data = unserialize(substr($session_data, $offset));
            $return_data[$varname] = $data;
            $offset += mb_orig_strlen(serialize($data));
        }

        return $return_data;
    }
}
