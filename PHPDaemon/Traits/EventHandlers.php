<?php
namespace PHPDaemon\Traits;
use PHPDaemon\Core\CallbackWrapper;

/**
 * Event handlers trait
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */

trait EventHandlers {
	/** Event handlers
	 * @var array
	 */
	protected $eventHandlers = [];

	/**
	 * Unshift $this to arguments of callback?
	 * @var boolean
	 */
	protected $addThisToEvents = true;

	/**
	 * Propagate event
	 * @param string Event name
	 * @param mixed  ... variable set of arguments ...
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
	
	/**
	 * Bind event
	 * @param string   Event name
	 * @param callable $cb Callback
	 * @return boolean Success
	 */
	public function bind($event, $cb) {
		if ($cb !== null) {
			$cb = CallbackWrapper::wrap($cb);
		}
		if (!isset($this->eventHandlers[$event])) {
			$this->eventHandlers[$event] = [];
		}
		$this->eventHandlers[$event][] = $cb;
		return true;
	}

	/**
	 * Unbind event or callback from event
	 * @param string Event name
	 * @param [callable Callback, optional
	 * @return boolean Success
	 */
	public function unbind($event, $cb = null) {
		if ($cb !== null) {
			$cb = CallbackWrapper::wrap($cb);
		}
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
