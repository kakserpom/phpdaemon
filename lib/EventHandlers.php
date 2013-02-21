<?php

/**
 * Event handlers trait
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

trait EventHandlers {
	private $eventHandlers = [];
	public function event() {
		$args = func_get_args();
		$name = array_shift($args);		
		if (isset($this->eventHandlers[$name])) {
			foreach ($this->eventHandlers[$name] as $cb) {
				call_user_func_array($cb, $args);
			}
		}
	}

	public function addEventHandler($event, $cb) {
		if (!isset($this->eventHandlers[$event])) {
			$this->eventHandlers[$event] = [];
		}
		$this->eventHandlers[$event][] = $cb;
	}

	
}