<?php
namespace PHPDaemon\Core;

/**
 * CallbackWrapper
 * @package Core
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class CallbackWrapper
{
    use \PHPDaemon\Traits\ClassWatchdog;
    use \PHPDaemon\Traits\StaticObjectWatchdog;

    /**
     * @var object Context
     */
    protected $context;

    /**
     * @var callable Callback
     */
    protected $cb;

    /**
     * @var callable Timer
     */
    protected $timer;

    /**
     * Constructor
     * @param  callable $cb
     * @param  double $timeout
     * @param  object $context
     * @return \PHPDaemon\Core\CallbackWrapper
     */
    protected function __construct($cb, $timeout = null, $context = null)
    {
        $this->cb = $cb;
        $this->context = $context;
        if ($timeout !== null) {
            $this->setTimeout($timeout);
        }
    }

    /**
     * @param double $timeout
     */
    public function setTimeout($timeout)
    {
        if ($timeout !== null) {
            $this->timer = Timer::add(function () {
                $this();
            }, $timeout);
        }
    }

    public static function addToArray(&$arr, $cb)
    {
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
        return true;
    }

    public static function extractCb($cb)
    {
        if ($cb instanceof CallbackWrapper) {
            return $cb->getCallback();
        }
        return $cb;
    }

    public function getCallback()
    {
        return $this->cb;
    }

    public static function removeFromArray(&$arr, $cb)
    {
        if ($arr === null) {
            $arr = [];
            return false;
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

    /**
     * Wraps callback
     * @static
     * @param callable $cb
     * @param double $timeout = null
     * @return \Closure
     */
    public static function wrap($cb, $timeout = null, $ctx = false)
    {
        if ($cb instanceof CallbackWrapper || ((Daemon::$context === null) && ($timeout === null))) {
            return $cb;
        }
        if ($cb === null) {
            return null;
        }
        return new static($cb, $timeout, $ctx !== false ? $ctx : Daemon::$context);
    }

    /**
     * Wraps callback even without context
     * @static
     * @param callable $cb
     * @param double $timeout = null
     * @return CallbackWrapper|null
     */
    public static function forceWrap($cb, $timeout = null)
    {
        if ($cb instanceof CallbackWrapper) {
            return $cb;
        }
        if ($cb === null) {
            return null;
        }
        return new static($cb, $timeout, Daemon::$context);
    }

    public function cancelTimeout()
    {
        if ($this->timer !== null) {
            Timer::remove($this->timer);
            $this->timer = null;
        }
    }

    /**
     * Cancel
     * @return void
     */
    public function cancel()
    {
        $this->cb = null;
        $this->context = null;
        if ($this->timer !== null) {
            Timer::remove($this->timer);
            $this->timer = null;
        }
    }

    /**
     * Unwraps callback
     * @return callable
     */
    public function unwrap()
    {
        return $this->cb;
    }

    /**
     * Invokes the callback
     * @param  mixed ...$args Arguments
     * @return mixed
     */
    public function __invoke(...$args)
    {
        if ($this->timer !== null) {
            Timer::remove($this->timer);
            $this->timer = null;
        }
        if ($this->cb === null) {
            return null;
        }
        if ($this->context === null || Daemon::$context !== null) {
            try {
                $cb = $this->cb;
                return $cb(...$args);
            } catch (\Exception $e) {
                Daemon::uncaughtExceptionHandler($e);
            }
            return;
        }
        $this->context->onWakeup();
        try {
            $cb = $this->cb;
            $result = $cb(...$args);
            $this->context->onSleep();
            return $result;
        } catch (\Exception $e) {
            Daemon::uncaughtExceptionHandler($e);
            $this->context->onSleep();
        }
    }
}
