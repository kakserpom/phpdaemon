<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class ConnectionOpenOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection
 */
class ConnectionOpenOkFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x000a0029;

    public $frameChannelId = 0;
    public $knownHosts = ''; // shortstr

    public static function create(
        $knownHosts = null
    )
    {
        $frame = new self();

        if (null !== $knownHosts) {
            $frame->knownHosts = $knownHosts;
        }

        return $frame;
    }
}
