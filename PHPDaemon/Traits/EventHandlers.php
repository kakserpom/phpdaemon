<?php
namespace PHPDaemon\Traits;
use PHPDaemon\Core\CallbackWrapper;
use PHPDaemon\Core\Daemon;

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

	/** Last called event name
	 * @var string
	 */
	protected $lastEventName;

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
			$this->lastEventName = $name;
			foreach ($this->eventHandlers[$name] as $cb) {
				call_user_func_array($cb, $args);
			}
		}
	}

	/** Use it to define event name, when one callback was bind to more than one events
	 * @return string
	 */
	public function getLastEventName() {
		return $this->lastEventName;
	}

	/**
	 * Bind event or events
	 * @param string|array   Event name
	 * @param callable $cb Callback
	 * @return boolean Success
	 */
	public function bind($event, $cb) {
		if ($cb !== null) {
			$cb = CallbackWrapper::wrap($cb);
		}
		is_array($event) or $event = [$event];
		foreach ($event as $e) {
			if (!isset($this->eventHandlers[$e])) {
				$this->eventHandlers[$e] = [];
			}
			$this->eventHandlers[$e][] = $cb;
		}
		return true;
	}

	/**
	 * Unbind event(s) or callback from event(s)
	 * @param string|array Event name
	 * @param callable Callback, optional
	 * @return boolean Success
	 */
	public function unbind($event, $cb = null) {
		if ($cb !== null) {
			$cb = CallbackWrapper::wrap($cb);
		}
		is_array($event) or $event = [$event];
		$success = true;
		foreach ($event as $e) {
			if (!isset($this->eventHandlers[$e])) {
				$success = false;
				continue;
			}
			if ($cb === null) {
				unset($this->eventHandlers[$e]);
				continue;
			}
			if (($p = array_search($cb, $this->eventHandlers[$e], true)) === false) {
				$success = false;
				continue;
			}
			unset($this->eventHandlers[$e][$p]);
		}
		return $success;
	}

	/**
	 * Clean up all events
	 * @return void
	 */
	protected function cleanupEventHandlers() {
		$this->eventHandlers = [];
		//Daemon::log('clean up event handlers '.get_class($this). ' -- '.$this->attrs->server['REQUEST_URI']. PHP_EOL .Debug::backtrace());
	}
}
