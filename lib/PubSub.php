<?php

/**
 * Thread
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

class PubSub {

	/**
	 * Storage of events
	 * @var hash [id => PubSubEvent, ...]
	 */
	protected $events = [];

	/**
	 * Subcribe to event 
	 * @param string Event ID
	 * @param object Subscriber
	 * @param callable Callback
	 * @return boolean Success
	 */
	public function sub($id, $obj, $cb) {
		if (!isset($this->events[$id])) {
			return false;
		}
		return $this->events[$id]->sub($obj, $cb);
	}

	/**
	 * Adds event
	 * @param string Event ID
	 * @param PubSubEvent
	 * @return void
	 */
	public function addEvent($id, PubSubEvent $obj) {
		$this->events[$id] = $obj;
	}

	/**
	 * Removes event
	 * @param string Event ID
	 * @return void
	 */
	public function removeEvent($id) {
		unset($this->events[$id]);
	}

	/**
	 * Unsubscribe object from event
	 * @param string Event ID
	 * @param object
	 * @return boolean Success
	 */
	public function unsub($id, $obj) {
		if (!isset($this->events[$id])) {
			return false;
		}
		return $this->events[$id]->unsub($obj);
	}

	/**
	 * Publish
	 * @param string Event ID
	 * @param mixed Data
	 * @return boolean Success
	 */
	public function pub($id, $data) {
		if (!isset($this->events[$id])) {
			return false;
		}
		return $this->events[$id]->pub($data);
	}

	/**
	 * Unsubscribe object from all events
	 * @param object
	 * @return boolean Success
	 */
	public function unsubFromAll($obj) {
		foreach ($this->events as $event) {
			$event->unsub($obj);
		}
		return true;
	}
}
