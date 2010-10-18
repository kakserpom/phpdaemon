<?php

/**
 * Timed event 
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com> 
 */
class Daemon_TimedEvent{
	public $ev;
	public $lastTimeout;
	
	public function __construct($cb,$timeout = NULL) {
		$this->ev = event_new();
		event_set($this->ev, STDIN, EV_TIMEOUT, $cb, array());
		// @todo get the worker from constructor
		event_base_set($this->ev, Daemon::$process->eventBase);
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
