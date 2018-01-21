<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class ConnectionBlockedFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection
 */
class ConnectionBlockedFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x000a003c;

    public $frameChannelId = 0;
    public $reason = ''; // shortstr

    public static function create(
        $reason = null
    )
    {
        $frame = new self();

        if (null !== $reason) {
            $frame->reason = $reason;
        }

        return $frame;
    }
}
