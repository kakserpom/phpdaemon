<?php
namespace PHPDaemon\Traits;

use PHPDaemon\Exceptions\UndefinedMethodCalled;

/**
 * Watchdog of __call and __callStatic
 * @package PHPDaemon\Traits
 * @author  Vasily Zorin <maintainer@daemon.io>
 */
trait ClassWatchdog
{
    /**
     * @param  string $method Method name
     * @param  array $args Arguments
     * @throws UndefinedMethodCalled if call to undefined static method
     * @return mixed
     */
    public static function __callStatic($method, $args)
    {
        throw new UndefinedMethodCalled('Call to undefined static method ' . get_called_class() . '::' . $method);
    }

    /**
     * @param  string $method Method name
     * @param  array $args Arguments
     * @throws UndefinedMethodCalled if call to undefined method
     * @return mixed
     */
    public function __call($method, $args)
    {
        throw new UndefinedMethodCalled('Call to undefined method ' . get_class($this) . '->' . $method);
    }
}
