<?php
namespace PHPDaemon\SockJS\TestRelay;

/**
 * @package    SockJS
 * @subpackage TestRelay
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Close extends \PHPDaemon\WebSocket\Route
{
    /**
     * Called when the connection is handshaked.
     * @return void
     */
    public function onHandshake()
    {
        $this->client->finish();
    }
}
