<?php

namespace PHPDaemon\Core;

use PHPDaemon\Network\IOStream;

/**
 * Process
 *
 * @package PHPDaemon\Core
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class ShellCommand extends IOStream
{

    /**
     * @var string Executable path
     */
    public $binPath;
    /**
     * @var string SUID
     */
    public $setUser;
    /**
     * @var string SGID
     */
    public $setGroup;
    /**
     * @var string Chroot
     */
    public $chroot = '/';
    /**
     * @var string Chdir
     */
    public    $cwd;
    protected $finishWrite;
    /**
     * @var string Command string
     */
    protected $cmd;
    /**
     * @var array Opened pipes
     */
    protected $pipes;
    /**
     * @var resource Process descriptor
     */
    protected $pd;
    /**
     * @var resource FD write
     */
    protected $fdWrite;
    /**
     * @var boolean Output errors?
     */
    protected $outputErrors = true;
    /**
     * @var array Hash of environment's variables
     */
    protected $env = [];
    /**
     * @var string Path to error logfile
     */
    protected $errlogfile = null;

    /**
     * @var array Array of arguments
     */
    protected $args;

    /**
     * @var integer Process priority
     */
    protected $nice;

    /**
     * @var \EventBufferEvent
     */
    protected $bevWrite;

    /**
     * @var \EventBufferEvent
     */
    protected $bevErr;

    /**
     * @var boolean Got EOF?
     */
    protected $EOF = false;

    /**
     * @param string $path
     *
     * @return $this
     */
    public function setErrorLog (string $path): self
    {
        $this->errlogfile = $path;

        return $this;
    }

    /**
     * Execute
     *
     * @param string   $binPath Binpath
     * @param callable $cb      Callback
     * @param array    $args    Optional. Arguments
     * @param array    $env     Optional. Hash of environment's variables
     */
    public static function exec ($binPath = null, $cb = null, $args = null, $env = null)
    {
        $o    = new static;
        $data = '';
        $o->bind(
            'read',
            function ($o) use (&$data) {
                $data .= $o->readUnlimited();
            }
        );
        $o->bind(
            'eof',
            function ($o) use (&$data, $cb) {
                $cb($o, $data);
                $o->close();
            }
        );
        $o->execute($binPath, $args, $env);
    }

    /**
     * @param bool $bool
     */
    public function setOutputErrors (bool $bool): void
    {
        $this->outputErrors = $bool;
    }

    /**
     * Execute
     *
     * @param string $binPath Optional. Binpath
     * @param array  $args    Optional. Arguments
     * @param array  $env     Optional. Hash of environment's variables
     *
     * @return this
     */
    public function execute ($binPath = null, $args = null, $env = null)
    {
        if ($binPath !== null) {
            $this->binPath = $binPath;
        }

        if ($env !== null) {
            $this->env = $env;
        }

        if ($args !== null) {
            $this->args = $args;
        }
        $this->cmd = $this->binPath . static::buildArgs($this->args) . ($this->outputErrors ? ' 2>&1' : '');

        if (isset($this->setUser) || isset($this->setGroup)) {
            if (isset($this->setUser) && isset($this->setGroup) && ($this->setUser !== $this->setGroup)) {
                $this->cmd = 'sudo -g ' . escapeshellarg($this->setGroup) . '  -u ' . escapeshellarg(
                        $this->setUser
                    ) . ' ' . $this->cmd;
            }
            else {
                $this->cmd = 'su ' . escapeshellarg($this->setGroup) . ' -c ' . escapeshellarg($this->cmd);
            }
        }

        if ($this->chroot !== '/') {
            $this->cmd = 'chroot ' . escapeshellarg($this->chroot) . ' ' . $this->cmd;
        }

        if ($this->nice !== null) {
            $this->cmd = 'nice -n ' . ((int)$this->nice) . ' ' . $this->cmd;
        }

        $pipesDescr = [
            0 => ['pipe', 'r'], // stdin is a pipe that the child will read from
            1 => ['pipe', 'w'] // stdout is a pipe that the child will write to
        ];

        if (($this->errlogfile !== null) && !$this->outputErrors) {
            $pipesDescr[2] = ['file', $this->errlogfile, 'a']; // @TODO: refactoring
        }

        $this->pd = proc_open($this->cmd, $pipesDescr, $this->pipes, $this->cwd, $this->env);
        if ($this->pd) {
            $this->setFd($this->pipes[1]);
        }

        return $this;
    }

    /**
     * Build arguments string from associative/enumerated array (may be mixed)
     *
     * @param array $args
     *
     * @return string
     */
    public static function buildArgs ($args)
    {
        if (!is_array($args)) {
            return '';
        }
        $ret = '';
        foreach ($args as $k => $v) {
            if (!is_int($v) && ($v !== null)) {
                $v = escapeshellarg($v);
            }
            if (is_int($k)) {
                $ret .= ' ' . $v;
            }
            else {
                if ($k[0] !== '-') {
                    $ret .= ' --' . $k . ($v !== null ? '=' . $v : '');
                }
                else {
                    $ret .= ' ' . $k . ($v !== null ? '=' . $v : '');
                }
            }
        }

        return $ret;
    }

    /**
     * Sets fd
     *
     * @param resource          $fd File descriptor
     * @param \EventBufferEvent $bev
     *
     * @return void
     */
    public function setFd ($fd, $bev = null)
    {
        $this->fd = $fd;
        if ($fd === false) {
            $this->finish();

            return;
        }
        $this->fdWrite = $this->pipes[0];
        $flags         = !is_resource($this->fd) ? \EventBufferEvent::OPT_CLOSE_ON_FREE : 0;
        $flags         |= \EventBufferEvent::OPT_DEFER_CALLBACKS; // buggy option

        if ($this->eventLoop === null) {
            $this->eventLoop = EventLoop::$instance;
        }
        $this->bev      = $this->eventLoop->bufferEvent(
            $this->fd,
            0,
            [$this, 'onReadEv'],
            null,
            [$this, 'onStateEv']
        );
        $this->bevWrite = $this->eventLoop->bufferEvent(
            $this->fdWrite,
            0,
            null,
            [$this, 'onWriteEv'],
            null
        );
        if (!$this->bev || !$this->bevWrite) {
            $this->finish();

            return;
        }
        if ($this->priority !== null) {
            $this->bev->priority = $this->priority;
        }
        if ($this->timeout !== null) {
            $this->setTimeout($this->timeout);
        }
        if (!$this->bev->enable(\Event::READ | \Event::TIMEOUT | \Event::PERSIST)) {
            $this->finish();

            return;
        }
        if (!$this->bevWrite->enable(\Event::WRITE | \Event::TIMEOUT | \Event::PERSIST)) {
            $this->finish();

            return;
        }
        $this->bev->setWatermark(\Event::READ, $this->lowMark, $this->highMark);
        $this->alive = true;

        init:
        if (!$this->inited) {
            $this->inited = true;
            $this->init();
        }
    }

    /**
     * Get command string
     *
     * @return string
     */
    public function getCmd ()
    {
        return $this->cmd;
    }

    /**
     * Set group
     *
     * @return this
     */
    public function setGroup ($val)
    {
        $this->setGroup = $val;

        return $this;
    }

    /**
     * Set cwd
     *
     * @param string $dir
     *
     * @return this
     */
    public function setCwd ($dir)
    {
        $this->cwd = $dir;

        return $this;
    }

    /**
     * Set group
     *
     * @param string $val
     *
     * @return this
     */
    public function setUser ($val)
    {
        $this->setUser = $val;

        return $this;
    }

    /**
     * Set chroot
     *
     * @param string $dir
     *
     * @return this
     */
    public function setChroot ($dir)
    {
        $this->chroot = $dir;

        return $this;
    }

    /**
     * Sets an array of arguments
     *
     * @param array Arguments
     *
     * @return this
     */
    public function setArgs ($args = null)
    {
        $this->args = $args;

        return $this;
    }

    /**
     * Set a hash of environment's variables
     *
     * @param array Hash of environment's variables
     *
     * @return this
     */
    public function setEnv ($env = null)
    {
        $this->env = $env;

        return $this;
    }

    /**
     * Set priority
     *
     * @param integer $nice Priority
     *
     * @return this
     */
    public function nice ($nice = null)
    {
        $this->nice = $nice;

        return $this;
    }

    /**
     * Finish write stream
     *
     * @return boolean
     */
    public function finishWrite ()
    {
        if (!$this->writing) {
            $this->closeWrite();
        }

        $this->finishWrite = true;

        return true;
    }

    /**
     * Close write stream
     *
     * @return this
     */
    public function closeWrite ()
    {
        if ($this->bevWrite) {
            if (isset($this->bevWrite)) {
                $this->bevWrite->free();
            }
            $this->bevWrite = null;
        }

        if ($this->fdWrite) {
            fclose($this->fdWrite);
            $this->fdWrite = null;
        }

        return $this;
    }

    /**
     * Called when stream is finished
     */
    public function onFinish ()
    {
        $this->onEofEvent();
    }

    /**
     * Called when got EOF
     *
     * @return void
     */
    public function onEofEvent ()
    {
        if ($this->EOF) {
            return;
        }
        $this->EOF = true;

        $this->event('eof');
    }

    /**
     * Got EOF?
     *
     * @return boolean
     */
    public function eof ()
    {
        return $this->EOF;
    }

    /**
     * Send data to the connection. Note that it just writes to buffer that flushes at every baseloop
     *
     * @param string $data Data to send
     *
     * @return boolean Success
     */
    public function write ($data)
    {
        if (!$this->alive) {
            Daemon::log('Attempt to write to dead IOStream (' . get_class($this) . ')');

            return false;
        }
        if (!isset($this->bevWrite)) {
            return false;
        }
        if (!mb_orig_strlen($data)) {
            return true;
        }
        $this->writing   = true;
        Daemon::$noError = true;
        if (!$this->bevWrite->write($data) || !Daemon::$noError) {
            $this->close();

            return false;
        }

        return true;
    }

    /**
     * Close the process
     *
     * @return void
     */
    public function close ()
    {
        parent::close();
        $this->closeWrite();
        if (is_resource($this->pd)) {
            proc_close($this->pd);
        }
    }

    /**
     * Send data and appending \n to connection. Note that it just writes to buffer flushed at every baseloop
     *
     * @param string Data to send
     *
     * @return boolean Success
     */
    public function writeln ($data)
    {
        if (!$this->alive) {
            Daemon::log('Attempt to write to dead IOStream (' . get_class($this) . ')');

            return false;
        }
        if (!isset($this->bevWrite)) {
            return false;
        }
        if (!mb_orig_strlen($data) && !mb_orig_strlen($this->EOL)) {
            return true;
        }
        $this->writing = true;
        $this->bevWrite->write($data);
        $this->bevWrite->write($this->EOL);

        return true;
    }

    /**
     * Sets callback which will be called once when got EOF
     *
     * @param callable $cb
     *
     * @return this
     */
    public function onEOF ($cb = null)
    {
        return $this->bind('eof', $cb);
    }

    public function getStatus ()
    {
        return !empty($this->pd) ? proc_get_status($this->pd) : null;
    }

    /**
     * Called when new data received
     *
     * @return this|null
     */
    protected function onRead ()
    {
        if (func_num_args() === 1) {
            $this->onRead = func_get_arg(0);

            return $this;
        }
        $this->event('read');
    }
}
