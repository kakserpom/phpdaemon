<?php

/**
 * CallbackWrapper
 * 
 * @package Core
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class CallbackWrapper {
	/**
	 * Context
	 * @var mixed
	 */
	public $context;

	/**
	 * Callback
	 * @var callable
	 */
	protected $cb;

	/**
	 * Constructor
	 * @param callable Callback
	 * @param mixed Context
	 * @return object
	 */
	public function __construct($cb, $context = null) {
		$this->cb = $cb;
		$this->context = $context;
	}

	/**
	 * Cancel
	 * @return void
	 */
	public function cancel() {
		$this->cb = null;
		$this->context = null;
	}

	/**
	 * Wraps callback
	 * @static
	 * @return object
	 */
	public static function wrap($cb) {
		if ($cb instanceof CallbackWrapper || (Daemon::$context === null)) {
			return $cb;
		}
		return new static($cb, Daemon::$context);
	}

	/**
	 * Unwraps callback
	 * @return callable
	 */
	public function unwrap() {
		return $this->cb;
	}

	/**
	 * Invokes the callback
	 * @return mixed
	 * @return void
	 */
	public function __invoke() {
		if ($this->cb === null) {
			return null;
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
