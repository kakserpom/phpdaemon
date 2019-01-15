<?php
namespace PHPDaemon\Structures;

use PHPDaemon\Core\CallbackWrapper;

/**
 * StackCallbacks
 * @package PHPDaemon\Structures
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
class StackCallbacks extends \SplStack
{
    /**
     * Push callback to the bottom of stack
     * @param  callable $cb Callback
     * @return void
     */
    public function push($cb)
    {
        parent::push(CallbackWrapper::wrap($cb));
    }

    /**
     * Executes one callback from the top with given arguments
     * @param  mixed ...$args Arguments
     * @return boolean
     */
    public function executeOne(...$args)
    {
        if ($this->isEmpty()) {
            return false;
        }
        $cb = $this->shift();
        if ($cb) {
            $cb(...$args);
            if ($cb instanceof CallbackWrapper) {
                $cb->cancel();
            }
        }
        return true;
    }

    /**
     * Executes one callback from the top with given arguments without taking it out
     * @param  mixed ...$args Arguments
     * @return boolean
     */
    public function executeAndKeepOne(...$args)
    {
        if ($this->isEmpty()) {
            return false;
        }
        $cb = $this->shift();
        $this->unshift($cb);
        if ($cb) {
            $cb(...$args);
        }
        return true;
    }

    /**
     * Push callback to the top of stack
     * @param  callable $cb Callback
     * @return void
     */
    public function unshift($cb)
    {
        parent::unshift(CallbackWrapper::wrap($cb));
    }

    /**
     * Executes all callbacks with given arguments
     * @param array $args
     * @return int
     */
    public function executeAll(...$args)
    {
        if ($this->isEmpty()) {
            return 0;
        }

        $n = 0;
        do {
            if ($cb = $this->shift()) {
                $cb(...$args);
                ++$n;
                if ($cb instanceof CallbackWrapper) {
                    $cb->cancel();
                }
            }
        } while (!$this->isEmpty());
        return $n;
    }

    /**
     * Return array
     * @return array
     */
    public function toArray()
    {
        $arr = [];
        while (!$this->isEmpty()) {
            $arr[] = $this->shift();
        }
        return $arr;
    }

    /**
     * Shifts all callbacks sequentially
     * @return void
     */
    public function reset()
    {
        while (!$this->isEmpty()) {
            $this->shift();
        }
    }
}
