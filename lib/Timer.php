<?php

/**
 * Timed event 
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com> 
 */
class Timer {
	
	public $id; // timer id
	public $ev; // event resource
	public $lastTimeout; // Current timeout holder
	public $finished = false; // Is the timer finished?
	public $cb; // callback
	public static $list = array(); // list of timers
	
	public function __construct($cb, $timeout = null, $id = null) {
		if ($id === null) {
			end(Timer::$list);
			$id = key(Timer::$list);
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
		event_set($this->ev, STDIN, EV_TIMEOUT, array('Timer', 'eventCall'), array($id));
		event_base_set($this->ev, Daemon::$process->eventBase);
		if ($timeout !== null) {
			$this->timeout($timeout);
		}
		Timer::$list[$id] = $this;
	}
	public static function eventCall($fd, $flags ,$args) {
		$id = $args[0];
		if (!isset(Timer::$list[$id])) {
			Daemon::log(__METHOD__.': bad event call.');
			return;
		}
		$obj = Timer::$list[$id];
		call_user_func($obj->cb,$obj);
		if ($obj->finished) {
			unset(Timer::$list[$id]);
		}
	}
	public static function add($cb, $timeout = null, $id = null) {
		$obj = new self($cb, $timeout, $id);
		return $obj->id;
	}
	public static function setTimeout($id,$timeout = NULL) {
		if (isset(Timer::$list[$id])) {
			Timer::$list[$id]->timeout($timeout);
			return true;
		}
		return false;
	}
	public static function remove($id) {
		unset(Timer::$list[$id]);
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
