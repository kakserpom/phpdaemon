<?php
namespace PHPDaemon\PubSub;

use PHPDaemon\PubSub\PubSubEvent;

/**
 * Thread
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */

class PubSub {
	use \PHPDaemon\Traits\ClassWatchdog;

	/**
	 * Storage of events
	 * @var array [id => PubSubEvent, ...]
	 */
	protected $events = [];

	/**
	 * Subcribe to event
	 * @param string $id   Event ID
	 * @param object $obj  Subscriber
	 * @param callable $cb Callback
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
	 * @param string $id Event ID
	 * @param PubSubEvent
	 * @return void
	 */
	public function addEvent($id, PubSubEvent $obj) {
		$this->events[$id] = $obj;
	}

	/**
	 * Removes event
	 * @param string $id Event ID
	 * @return void
	 */
	public function removeEvent($id) {
		unset($this->events[$id]);
	}

	/**
	 * Unsubscribe object from event
	 * @param string $id Event ID
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
	 * @param string $id  Event ID
	 * @param mixed $data Data
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
