<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class QueueDeleteOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue
 */
class QueueDeleteOkFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x00320029;

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
