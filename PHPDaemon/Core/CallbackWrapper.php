<?php
namespace PHPDaemon\Core;

/**
 * CallbackWrapper
 *
 * @package Core
 *
 * @author  Zorin Vasily <maintainer@daemon.io>
 */
class CallbackWrapper {
	use \PHPDaemon\Traits\ClassWatchdog;
	use \PHPDaemon\Traits\StaticObjectWatchdog;

	/**
	 * Context
	 * @var object
	 */
	protected $context;

	/**
	 * Callback
	 * @var callable
	 */
	protected $cb;

	/**
	 * Timer
	 * @var callable
	 */
	protected $timer;

	/**
	 * Constructor
	 * @param callable $cb
	 * @param object $context
	 * @return \PHPDaemon\Core\CallbackWrapper
	 */
	public function __construct($cb, $context = null, $timeout = null) {
		$this->cb      = $cb;
		$this->context = $context;
		if ($timeout !== null) {
			$this->timeout($timeout);
		}
	}

	public function setTimeout($timeout) {
		if ($timeout !== null) {
			$this->timer = Timer::add(function ($timer) {
				$this();
			}, $timeout);
		}
	}

	public function cancelTimeout() {
		if ($this->timer !== null) {
			$this->timer->free();
			$this->timer = null;
		}
	}

	public function getCallback() {
		return $this->cb;
	}

	/**
	 * Cancel
	 * @return void
	 */
	public function cancel() {
		$this->cb      = null;
		$this->context = null;
		if ($this->timer !== null) {
			$this->timer->free();
			$this->timer = null;
		}
	}

	public static function addToArray(&$arr, $cb) {
		if ($arr === null) {
			$arr = [];
		}
		$e = static::extractCb($cb);
		foreach ($arr as $item) {
			if (static::extractCb($item) === $e) {
				return false;
			}
		}
		$arr[] = $cb;
		return $arr;
	}

	public static function removeFromArray(&$arr, $cb) {
		if ($arr === null) {
			$arr = [];
		}
		$e = static::extractCb($cb);
		foreach ($arr as $k => $item) {
			if (static::extractCb($item) === $e) {
				unset($arr[$k]);
				return true;
			}
		}
		return false;
	}

	public static function extractCb($cb) {
		if ($cb instanceof CallbackWrapper) {
			return $cb->getCallback();
		}
		return $cb;
	}

	/**
	 * Wraps callback
	 * @static
	 * @param callable $cb
	 * @param double $timeout = null
	 * @return object
	 */
	public static function wrap($cb, $timeout = null) {
		if ($cb instanceof CallbackWrapper || ((Daemon::$context === null) && ($timeout === null))) {
			return $cb;
		}
		if ($cb === null) {
			return null;
		}
		if (!is_callable($cb)) {
			\PHPDaemon\Core\Daemon::log(\PHPDaemon\Core\Debug::dump($cb));

		}
		return new static($cb, Daemon::$context, $timeout);
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
		if ($this->timer !== null) {
			$this->timer->free();
			$this->timer = null;
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
