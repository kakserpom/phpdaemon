<?php
namespace PHPDaemon\Traits;
use PHPDaemon\Core\DeferredEvent;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Exceptions\UndefinedMethodCalled;
use PHPDaemon\Exceptions\UndefinedEventCalledException;

/**
 * Deferred event handlers trait
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */

trait DeferredEventHandlers {
	protected $DefEvHandlersUsed = false;
	/**
	 * @param string $event
	 * @throws UndefinedEventCalledException
	 * @return null|mixed
	 */
	public function __get($event) {
		if (!$this->DefEvHandlersUsed) {
			$this->DefEvHandlersUsed = true;
			$this->firstDeferredEventUsed();
		}
		if (substr($event, 0, 2) !== 'on') {
			return $this->{$event};
		}
		if (!method_exists($this, $event . 'Event')) {
			throw new \PHPDaemon\Exceptions\UndefinedEventCalled('Undefined event called: ' . get_class($this). '->' . $event);
		}
		$e = new DeferredEvent($this->{$event . 'Event'}());
		$e->name = $event;
		$e->parent = $this;
		$this->{$event} = &$e;
		return $e;
	}

	protected function firstDeferredEventUsed() {}

	public function cleanupDeferredEventHandlers() {
		foreach ($this as $key => $property) {
			if ($property instanceof DeferredEvent) {
				$property->cleanup();
				$this->{$key} = null;
			}
		}
	}

	/**
	 * @param $args
	 * @throws UndefinedMethodCalled
	 * @return mixed
	 */
	public function __call($method, $args) {
		if (substr($method, 0, 2) !== 'on') {
			// @TODO: exception
			throw new UndefinedMethodCalled('Call to undefined method ' . get_class($this) . '->' . $method);
		}
		$o = $this->{$method};
		if (!$o) {
			throw new UndefinedMethodCalled('Call to undefined method ' . get_class($this) . '->' . $method);
		}
		return call_user_func_array($o, $args);
	}
}
