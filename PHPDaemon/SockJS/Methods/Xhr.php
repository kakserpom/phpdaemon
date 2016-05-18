<?php
namespace PHPDaemon\SockJS\Methods;

/**
 * @package    Libraries
 * @subpackage SockJS
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Xhr extends Generic
{
    protected $delayedStopEnabled = true;
    protected $contentType = 'application/javascript';
    protected $poll = true;
    protected $pollMode = ['one-by-one'];
    protected $allowedMethods = 'POST';

    /**
     * Send frame
     * @param  string $frame
     * @return void
     */
    protected function sendFrame($frame)
    {
        $this->outputFrame($frame . "\n");
        parent::sendFrame($frame);
    }
}
