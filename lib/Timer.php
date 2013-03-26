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
	public static $list = []; // list of timers
	public $priority;
	static $counter = 0;
	
	public function __construct($cb, $timeout = null, $id = null, $priority = null) {
		if ($id === null) {
			$id = ++self::$counter;
		}
		$this->id = $id;
		$this->cb = $cb;
		$this->ev = Event::timer(Daemon::$process->eventBase, [$this, 'eventCall']);
		if ($priority !== null) {
			$this->setPriority($priority);
		}
		if ($timeout !== null) {
			$this->timeout($timeout);
		}
		Timer::$list[$id] = $this;
	}
	public function eventCall($arg) {
		try {
			//Daemon::log('cb - '.Debug::zdump($this->cb));
			call_user_func($this->cb, $this);
		} catch (Exception $e) {
			Daemon::uncaughtExceptionHandler($e);
		}
	}
	public function setPriority($priority) {
		$this->priority = $priority;
		$this->ev->priority = $priority;
	}
	public static function add($cb, $timeout = null, $id = null, $priority = null) {
		$obj = new self($cb, $timeout, $id, $priority);
		return $obj->id;
	}
	public static function setTimeout($id, $timeout = NULL) {
		if (isset(Timer::$list[$id])) {
			Timer::$list[$id]->timeout($timeout);
			return true;
		}
		return false;
	}
	public static function remove($id) {
		if (isset(Timer::$list[$id])) {
			Timer::$list[$id]->free();
		}
	}
	public static function cancelTimeout($id) {
		if (isset(Timer::$list[$id])) {
			Timer::$list[$id]->cancel();
		}
	}
	public function timeout($timeout = null)	{
		if ($timeout !== null) {
			$this->lastTimeout = $timeout;
		}
		$this->ev->add($this->lastTimeout / 1e6);
	}
	public function cancel() {
		$this->ev->del();
	}
	public function finish(){
		$this->free();
	}
	public function __destruct() {
		$this->free();
	}
	public function free() {
		unset(Timer::$list[$this->id]);
		if ($this->ev !== null) {
			$this->ev->free();
			$this->ev = null;
		}
	}
}
function setTimeout($cb, $timeout = null, $id = null, $priority = null) {
	return Timer::add($cb, $timeout, $id, $priority);
}
function clearTimeout($id) {
	Timer::remove($id);
}
