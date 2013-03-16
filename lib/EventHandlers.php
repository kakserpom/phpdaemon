<?php

/**
 * Event handlers trait
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

trait EventHandlers {
	protected $eventHandlers = [];
	protected $addThisToEvents = true;
	public function event() {
		$args = func_get_args();
		$name = array_shift($args);
		if ($this->addThisToEvents) {
			array_unshift($args, $this);
		}
		if (isset($this->eventHandlers[$name])) {
			foreach ($this->eventHandlers[$name] as $cb) {
				call_user_func_array($cb, $args);
			}
		}
	}

	public function addEventHandler($event, $cb) { // @todo: remove in 1.0
		return $this->bind($event, $cb);
	}

	public function removeEventHandler($event, $cb = null) { // @todo: remove in 1.0
		return $this->unbind($event, $cb);
	}

	public function bind($event, $cb) {
		if (!isset($this->eventHandlers[$event])) {
			$this->eventHandlers[$event] = [];
		}
		$this->eventHandlers[$event][] = $cb;
	}

	public function unbind($event, $cb = null) {
		if (!isset($this->eventHandlers[$event])) {
			return false;
		}
		if ($cb === null) {
			unset($this->eventHandlers[$event]);
			return true;
		}
		if (($p = array_search($cb, $this->eventHandlers[$event], true)) === false) {
			return false;
		}
		unset($this->eventHandlers[$event][$p]);
		return true;
	}
}