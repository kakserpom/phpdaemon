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
	 * @param callable $cb
	 * @param \PHPDaemon\Core\TransportContext $context
	 * @return \PHPDaemon\Core\CallbackWrapper
	 */
	public function __construct($cb, $context = null) {
		$this->cb      = $cb;
		$this->context = $context;
	}

	/**
	 * Cancel
	 * @return void
	 */
	public function cancel() {
		$this->cb      = null;
		$this->context = null;
	}

	/**
	 * Wraps callback
	 * @static
	 * @param callable $cb
	 * @return object
	 */
	public static function wrap($cb) {
		if ($cb instanceof CallbackWrapper || (Daemon::$context === null)) {
			return $cb;
		}
		if ($cb === null) {
			return null;
		}
		if (!is_callable($cb)) {
			\PHPDaemon\Core\Daemon::log(\PHPDaemon\Core\Debug::dump($cb));

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
