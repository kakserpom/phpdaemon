<?php
namespace PHPDaemon\SockJS\Methods;

/**
 * @package    Libraries
 * @subpackage SockJS
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Websocket extends Generic
{
    protected $contentType = 'text/plain';

    /**
     * Constructor
     * @return void
     */
    public function init()
    {
        $this->header('426 Upgrade Required');
        $this->finish();
    }

    /**
     * Called when request iterated
     * @return void
     */
    public function run()
    {
    }
}
