<?php

/**
 * Timed event 
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com> 
 */
class Daemon_TimedEvent {
	
	public $id;
	public $ev;
	public $lastTimeout;
	public $finished = false;
	public $cb;
	
	public function __construct($cb, $timeout = null, $id = null) {
		if ($id === null) {
			end(Daemon::$process->timeouts);
			$id = key(Daemon::$process->timeouts);
			if ($id === null) {
				$id = 1;
			}
			else {
				++$id;
			}
		}
		$this->id = $id;
		$this->cb = $cb;
		$this->ev = event_new();
		event_set($this->ev, STDIN, EV_TIMEOUT, array('Daemon_TimedEvent', 'eventCall'), array($id));
		event_base_set($this->ev, Daemon::$process->eventBase);
		if ($timeout !== null) {
			$this->timeout($timeout);
		}
		Daemon::$process->timeouts[$id] = $this;
	}
	public static function eventCall($fd,$flags,$args) {
		$id = $args[0];
		if (!isset(Daemon::$process->timeouts[$id])) {
			Daemon::log(__METHOD__.': bad event call.');
			return;
		}
		$obj = Daemon::$process->timeouts[$id];
		call_user_func($obj->cb,$obj);
		if ($obj->finished) {
		 unset(Daemon::$process->timeouts[$id]);
		}
	}
	public static function add($cb, $timeout = null, $id = null) {
		$obj = new self($cb, $timeout, $id);
		return $obj->id;
	}
	public static function setTimeout($id,$timeout = NULL) {
		if (isset(Daemon::$process->timeouts[$id])) {
			Daemon::$process->timeouts[$id]->timeout($timeout);
			return true;
		}
		return false;
	}
	public static function remove($id) {
		unset(Daemon::$process->timeouts[$id]);
	}
	public function timeout($timeout = null)	{
	 if ($timeout !== null) {
		$this->lastTimeout = $timeout;
	}
	 event_add($this->ev, $this->lastTimeout);
	}
	public function finish()	{
		$this->finished = true;
	}
	public function __destruct() {
		event_del($this->ev);
		event_free($this->ev);
	}
}
