<?php
/**
 * @author Patsura Dmitry https://github.com/ovr <talk@dmtry.me>
 */

namespace Tests;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\EventLoop;
use PHPDaemon\Core\Timer;
use PHPDaemon\FS\FileSystem;

abstract class AbstractTestCase extends \PHPUnit_Framework_TestCase
{
    protected function prepareAsync()
    {
        EventLoop::init();
        Daemon::initSettings();
        FileSystem::init();
        FileSystem::initEvent();
    }

    protected function completeAsync() {
        EventLoop::$instance->stop();
    }
    protected function runAsync($method, $timeout = 3e6)
    {
        Timer::add(function () use ($method) {
            self::assertSame(0, 1, 'Some callbacks didnt finished in ' . $method);
        }, $timeout);
        
        EventLoop::$instance->run();
        EventLoop::$instance->free();
        EventLoop::$instance = null;
    }
}
