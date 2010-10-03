<?php

/**************************************************************************/
/* phpDaemon
/* Web: http://github.com/kakserpom/phpdaemon
/* ===========================
/* @class Daemon_TimedEvent
/* @author kak.serpom.po.yaitsam@gmail.com
/* @description Timed Event implementation
/**************************************************************************/

class Daemon_TimedEvent{
	public $ev;
	public $lastTimeout;
	
	public function __construct($cb,$timeout = NULL) {
		$this->ev = event_new();
		event_set($this->ev, STDIN, EV_TIMEOUT, $cb, array());
		event_base_set($this->ev, Daemon::$worker->eventBase);
		if ($timeout !== NULL) {$this->timeout($timeout);}
	}
	public function timeout($timeout = NULL)
	{
	 if ($timeout !== NULL) {$this->lastTimeout = $timeout;}
	 event_add($this->ev, $this->lastTimeout);
	}
	public function __destruct()
	{
	 event_del($this->ev);
	 event_free($this->ev);
	}
}
