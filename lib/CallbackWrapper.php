<?php

/**
 * CallbackWrapper
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class CallbackWrapper {
	public $context;
	public $cb;
	public function __construct($cb, $context = null) {
		$this->cb = $cb;
		$this->context = $context;
	}
	public function cancel() {
		$this->cb = null;
		$this->context = null;
	}
	public static function wrap($cb) {
		if ($cb instanceof CallbackWrapper) {
			return $cb;
		}
		$class = get_called_class();
		return new $class($cb, Daemon::$context);
	}
	public function __invoke() {
		if ($this->cb === null) {
			return;
		}
		if ($this->context === null || Daemon::$context !== null) {
			return call_user_func_array($this->cb, func_get_args());	
		}
		$this->context->onWakeup();
		$result = call_user_func_array($this->cb, func_get_args());
		$this->context->onSleep();
		return $result;
	}
}
