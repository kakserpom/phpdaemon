<?php

return new LockServer;
class LockServer extends AsyncServer
{
    public $sessions = array(); // Active sessions
    public $lockState = array(); // States of jobs
    public $lockConnState = array(); // Array of session's state
    /* @method init
    @description Constructor.
    @return void
    */
    public function init()
    {
        Daemon::addDefaultSettings(array(
            'mod'.$this->modname.'listen' => 'tcp://0.0.0.0',
            'mod'.$this->modname.'listenport' => 833,
            'mod'.$this->modname.'allowedclients' => '127.0.0.1',
            'mod'.$this->modname.'enable' => 0,
        ));
        if (Daemon::$settings['mod' . $this->modname . 'enable']) {
            Daemon::log(__CLASS__ . ' up.');
            $this->allowedClients = explode(',', Daemon::$settings['mod' . $this->modname . 'allowedclients']);
            $this->bindSockets(Daemon::$settings['mod' . $this->modname . 'listen'], Daemon::$settings['mod' . $this->modname .
                'listenport']);
        }
    }
    /* @method onAccepted
    @description Called when new connection is accepted.
    @param integer Connection's ID.
    @param string Address of the connected peer.
    @return void
    */
    public function onAccepted($connId, $addr)
    {
        if ((($p = strrpos($addr, ':')) !== FALSE) && !$this->netMatch($this->allowedClients, substr($addr, 0, $p))) {
            return FALSE;
        }
        $this->sessions[$connId] = new LockServerSession($connId, $this);
        $this->sessions[$connId]->clientAddr = $addr;
    }
}
class LockServerSession extends SocketSession
{
    public $server = FALSE; // Is this S2S-session?
    public $locks = array(); // State of locks.
    /* @method acquireLock
    @param string Name of job.
    @param boolean Wait if already acquired?
    @description Called when client is trying to acquire lock.
    @return string Result.
    */
    public function acquireLock($name, $wait = FALSE)
    {
        if (!isset($this->appInstance->lockState[$name])) {
            $this->appInstance->lockState[$name] = 1;
            $this->appInstance->lockConnState[$name] = array($this->connId => 1);
            $this->locks[$name] = 1;
            return 'RUN';
        }
        if ($this->appInstance->lockState[$name] === 1) {
            if ($wait) {
                $this->appInstance->lockConnState[$name][$this->connId] = 2;
                $this->locks[$name] = 2;
                return 'WAIT';
            }
            return 'FAILED';
        }
    }
    /* @method done
    @param string Name of job.
    @param string Result.
    @description Called when client sends done- or failed-event.
    @return string Result.
    */
    public function done($name, $result)
    {
        if (isset($this->appInstance->lockState[$name]) && ($this->appInstance->lockState[$name] === 1) && isset($this->
            appInstance->lockConnState[$name][$this->connId]) && ($this->appInstance->lockConnState[$name][$this->connId] === 1)) {
            foreach ($this->appInstance->lockConnState[$name] as $connId => $state) {
                if (isset($this->appInstance->sessions[$connId])) {
                    $this->appInstance->sessions[$connId]->writeln($result . ' ' . $name);
                    unset($this->appInstance->sessions[$connId]->locks[$name]);
                }
            }
            unset($this->appInstance->lockState[$name]);
            unset($this->appInstance->lockConnState[$name]);
        }
    }
    /* @method onFinish
    @description Event of SocketSession (asyncServer).
    @return void
    */
    public function onFinish()
    {
        if (Daemon::$settings['logevents']) {
            Daemon::log(__METHOD__ . ' invoked');
        }
        foreach ($this->locks as $name => $status) {
            if ($status === 1) {
                $this->done($name, 'FAILED');
            } elseif ($status === 2) {
                unset($this->appInstance->lockConnState[$name][$this->connId]);
            }
        }
        unset($this->appInstance->sessions[$this->connId]);
    }
    /* @method stdin
    @description Called when new data received.
    @param string New data.
    @return void
    */
    public function stdin($buf)
    {
        $this->buf .= $buf;
        while (($l = $this->gets()) !== FALSE) {
            $l = rtrim($l, "\r\n");
            $e = explode(' ', $l);
            if ($e[0] === 'acquire') {
                $this->writeln($this->acquireLock($e[1]) . ' ' . $e[1]);
            } elseif ($e[0] === 'acquireWait') {
                $this->writeln($this->acquireLock($e[1], TRUE) . ' ' . $e[1]);
            } elseif ($e[0] === 'done') {
                $this->done($e[1], 'DONE');
            } elseif ($e[0] === 'failed') {
                $this->done($e[1], 'FAILED');
            } elseif ($e[0] === 'quit') {
                $this->finish();
            } elseif ($e[0] !== '') {
                $this->writeln('PROTOCOL_ERROR');
            }
        }
        if ((strpos($this->buf, "\xff\xf4\xff\xfd\x06") !== FALSE) || (strpos($this->buf, "\xff\xec") !== FALSE)) {
            $this->finish();
        }
    }
}
