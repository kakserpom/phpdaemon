<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class QueuePurgeOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue
 */
class QueuePurgeOkFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x0032001f;

    public $frameChannelId = 0;
    public $messageCount; // long

    public static function create(
        $messageCount = null
    )
    {
        $frame = new self();

        if (null !== $messageCount) {
            $frame->messageCount = $messageCount;
        }

        return $frame;
    }
}
