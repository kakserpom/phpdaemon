<?php

/**
 * Thread
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

class PubSub {
	public $events = array();
	public function sub($id, $obj, $cb) {
		if (!isset($this->events[$id])) {
			return false;
		}
		return $this->events[$id]->sub($obj, $cb);
	}
	public function addEvent($id, $obj) {
		$this->events[$id] = $obj;
	}
	public function removeEvent($id) {
		unset($this->events[$id]);
	}
	public function unsub($id, $obj) {
		if (!isset($this->events[$id])) {
			return false;
		}
		return $this->events[$id]->unsub($obj);
	}
	public function pub($id, $data) {
		if (!isset($this->events[$id])) {
			return false;
		}
		return $this->events[$id]->pub($data);
	}
	public function unsubFromAll($obj) {
		foreach ($this->events as $event) {
			$event->unsub($obj);
		}
		return true;
	}
}
