<?php
/**
 * Event handlers trait
 *
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */

trait EventHandlers {
	/* Event handlers
	 * @var hash
	 */
	protected $eventHandlers = [];

	/* Unshift $this to arguments of callback? 
	 * @var boolean
	 */
	protected $addThisToEvents = true;

	/* Propagate event
	 * @param string Event name
	 * @param mixed ... variable set of arguments ...
	 * @return void
	 */
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

	/* Alias of bind()
	 * @alias bind
	 */
	public function addEventHandler($event, $cb) { // @todo: remove in 1.0
		return $this->bind($event, $cb);
	}

	/* Alias of unbind()
	 * @alias unbind
	 */
	public function removeEventHandler($event, $cb = null) { // @todo: remove in 1.0
		return $this->unbind($event, $cb);
	}

	/* Bind event
	 * @param string Event name
	 * @param callable Callback
	 * @return boolean Success
	 */
	public function bind($event, $cb) {
		if (!isset($this->eventHandlers[$event])) {
			$this->eventHandlers[$event] = [];
		}
		$this->eventHandlers[$event][] = $cb;
		return true;
	}


	/* Unbind event or callback from event
	 * @param string Event name
	 * @param [callable Callback, optional
	 * @return boolean Success
	 */
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
