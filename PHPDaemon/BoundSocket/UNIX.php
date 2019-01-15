<?php
namespace PHPDaemon\BoundSocket;

use PHPDaemon\Core\Daemon;

/**
 * UNIX
 *
 * @package Core
 *
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class UNIX extends Generic
{
    /**
     * @var \PHPDaemon\Config\Section
     */
    public $config;
    /**
     * Group
     * @var string
     */
    protected $group;

    /**
     * User
     * @var string
     */
    protected $user;

    /**
     * Path
     * @var string
     */
    protected $path;
    /**
     * Listener mode?
     * @var boolean
     */
    protected $listenerMode = false;

    /**
     * toString handler
     * @return string
     */
    public function __toString()
    {
        return $this->path;
    }

    /**
     * Bind socket
     * @return boolean Success.
     */
    public function bindSocket()
    {
        if ($this->erroneous) {
            return false;
        }

        if ($this->path === null && isset($this->uri['path'])) {
            $this->path = $this->uri['path'];
        }

        if (pathinfo($this->path, PATHINFO_EXTENSION) !== 'sock') {
            Daemon::$process->log('Unix-socket \'' . $this->path . '\' must has \'.sock\' extension.');
            return false;
        }

        if (file_exists($this->path)) {
            unlink($this->path);
        }

        if ($this->listenerMode) {
            $this->setFd('unix:' . $this->path);
            return true;
        }
        $sock = socket_create(AF_UNIX, SOCK_STREAM, 0);
        if (!$sock) {
            $errno = socket_last_error();
            Daemon::$process->log(get_class($this) . ': Couldn\'t create UNIX-socket (' . $errno . ' - ' . socket_strerror($errno) . ').');
            return false;
        }

        // SO_REUSEADDR is meaningless in AF_UNIX context
        if (!@socket_bind($sock, $this->path)) {
            if (isset($this->config->maxboundsockets->value)) { // no error-messages when maxboundsockets defined
                return false;
            }
            $errno = socket_last_error();
            Daemon::$process->log(get_class($this) . ': Couldn\'t bind Unix-socket \'' . $this->path . '\' (' . $errno . ' - ' . socket_strerror($errno) . ').');
            return false;
        }
        socket_set_nonblock($sock);
        $this->onBound();
        if (!socket_listen($sock, SOMAXCONN)) {
            $errno = socket_last_error();
            Daemon::$process->log(get_class($this) . ': Couldn\'t listen UNIX-socket \'' . $this->path . '\' (' . $errno . ' - ' . socket_strerror($errno) . ')');
        }
        $this->setFd($sock);
        return true;
    }

    /**
     * Called when socket is bound
     * @return boolean Success
     */
    protected function onBound()
    {
        touch($this->path);
        chmod($this->path, 0770);
        if ($this->group === null && !empty($this->uri['pass'])) {
            $this->group = $this->uri['pass'];
        }
        if ($this->group === null && isset(Daemon::$config->group->value)) {
            $this->group = Daemon::$config->group->value;
        }
        if ($this->group !== null) {
            if (!@chgrp($this->path, $this->group)) {
                unlink($this->path);
                Daemon::log('Couldn\'t change group of the socket \'' . $this->path . '\' to \'' . $this->group . '\'.');
                return false;
            }
        }
        if ($this->user === null && !empty($this->uri['user'])) {
            $this->user = $this->uri['user'];
        }
        if ($this->user === null && isset(Daemon::$config->user->value)) {
            $this->user = Daemon::$config->user->value;
        }
        if ($this->user !== null) {
            if (!@chown($this->path, $this->user)) {
                unlink($this->path);
                Daemon::log('Couldn\'t change owner of the socket \'' . $this->path . '\' to \'' . $this->user . '\'.');
                return false;
            }
        }
        return true;
    }
}
