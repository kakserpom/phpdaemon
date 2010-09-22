<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class AsyncRTEPclient
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description AsyncRTEPclient class
/**************************************************************************/
class AsyncRTEPclient extends AsyncStream
{
    public $buf = '';
    public $servers = array();
    public $trace = FALSE;
    public $connected = FALSE;
    public $seq = 0;
    public $callbacks = array();
    public function addServer($addr)
    {
        $this->servers[] = $addr;
        return TRUE;
    }
    public function connect()
    {
        if ($this->connected) {
            return TRUE;
        }
        foreach ($this->servers as & $addr) {
            try {
                if ($this->initStream($addr)) {
                    $this->enable();
                    return $this->connected = TRUE;
                }
            }
            catch (BadStreamDescriptorException $e) {
                Daemon::log(get_class($e) . ': Couldn\'t connect to ' . $addr . '.');
            }
        }
        return FALSE;
    }
    public function addEventCallback($eventName, $callback)
    {
        if (!isset($this->callbacks[$eventName])) {
            $this->callbacks[$eventName] = array();
            $this->request(array('op' => 'subscribeEvents', 'events' => array($eventName)));
        }
        $this->callbacks[$eventName][] = $callback;
        return TRUE;
    }
    public function request($req)
    {
        if (!$this->connect()) {
            Daemon::log('RTEPClient: Couldn\'t send request.');
        }
        return $this->write(json_encode(array('id' => ++$this->seq, 'type' => 'request', 'request' => $req)) . "\n");
    }
    public function onReadEvent($buf, $arg = NULL)
    {
        if (Daemon::$settings['logevents']) {
            Daemon::log(__METHOD__ . '()');
        }
        while (($data = $this->read()) !== '') {
            if ($data === FALSE) {
                throw new Exception('read() returned false');
            }
            $this->buf .= $data;
            while (($l = $this->gets()) !== FALSE) {
                $this->onPacket(json_decode($l, TRUE));
            }
        }
        $this->eof();
    }
    public function onPacket($packet)
    {
        if ($packet['type'] === 'response') {
            $this->onResponse($packet);
        } elseif ($packet['type'] === 'request') {
            $this->onRequest($packet);
        } elseif ($packet['type'] === 'event') {
            $this->onEvent($packet);
        }
    }
    public function onResponse($packet)
    {
        if (Daemon::$settings['logevents']) {
            Daemon::log(__METHOD__ . ': ' . Daemon::var_dump($packet));
        }
    }
    public function onEvent($packet)
    {
        if (Daemon::$settings['logevents']) {
            Daemon::log(__METHOD__ . ': ' . Daemon::var_dump($packet));
        }
        $event = $packet['event'];
        if (!isset($this->callbacks[$event['name']])) {
            return FALSE;
        }
        foreach ($this->callbacks[$event['name']] as $cb) {
            call_user_func($cb, $event);
        }
        return TRUE;
    }
    public function onRequest($packet)
    {
        if (Daemon::$settings['logevents']) {
            Daemon::log(__METHOD__ . ': ' . Daemon::var_dump($packet));
        }
    }
    public function gets()
    {
        $p = strpos($this->buf, "\n");
        if ($p === FALSE) {
            return FALSE;
        }
        $r = binarySubstr($this->buf, 0, $p + 1);
        $this->buf = binarySubstr($this->buf, $p + 1);
        return $r;
    }
}
