<?php
namespace PHPDaemon\Core;

class SyncWrapper
{
    protected $obj;

    /**
     * SyncWrapper constructor.
     * @param $obj
     */
    public function __construct($cb) {
        EventLoop::$instance || EventLoop::init();
        $this->obj = $cb($this);
    }


    /**
     * Abstract call
     * @param $method
     * @param $args
     */
    public function __call($method, $args) {
        $args[] = function($arg) use (&$ret) {
            $ret = $arg->result;
            EventLoop::$instance->stop();
        };
        $this->obj->$method(...$args);
        EventLoop::$instance->run();
        return $ret;
    }
}
