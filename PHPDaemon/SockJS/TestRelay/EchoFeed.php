<?php
namespace PHPDaemon\SockJS\TestRelay;

/**
 * @package    SockJS
 * @subpackage TestRelay
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class EchoFeed extends \PHPDaemon\WebSocket\Route
{
    /**
     * Called when new frame received
     * @param  string $data Frame's contents
     * @param  integer $type Frame's type
     * @return void
     */
    public function onFrame($data, $type)
    {
        $this->client->sendFrame($data);
    }
}
