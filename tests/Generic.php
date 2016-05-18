<?php
/**
 * @author Patsura Dmitry https://github.com/ovr <talk@dmtry.me>
 */

namespace Tests;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\EventLoop;
use PHPDaemon\Core\Timer;
use PHPDaemon\Thread\Master;
use PHPDaemon\Utils\Crypt;

class Generic extends \PHPUnit_Framework_TestCase
{
    protected function prepareAsync()
    {
        EventLoop::init();
    }

    protected function completeAsync() {
        Event::$loop->stop();
    }
    protected function runAsync($method, $timeout = 3e6)
    {
        Timer::add(function () use ($method) {
            self::assertSame(0, 1, 'Some callbacks didnt finished in ' . $method);
        }, $timeout);
        $this->loop->run();
        Event::$instance->free();
        Event::$instance = null;
    }
}
