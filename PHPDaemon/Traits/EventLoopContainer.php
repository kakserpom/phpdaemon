<?php
/**
 * Created by PhpStorm.
 * User: vasilyzorin
 * Date: 25/05/16
 * Time: 01:47
 */

namespace PHPDaemon\Traits;


trait EventLoopContainer
{
    public $eventLoop;
    public function setEventLoop(EventLoop $eventLoop) {
        $this->eventLoop = $eventLoop;
        return $this;
    }
}