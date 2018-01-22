<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class QueueDeclareOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue
 */
class QueueDeclareOkFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x0032000b;

    public $frameChannelId = 0;
    public $queue; // shortstr
    public $messageCount; // long
    public $consumerCount; // long

    public static function create(
        $queue = null, $messageCount = null, $consumerCount = null
    )
    {
        $frame = new self();

        if (null !== $queue) {
            $frame->queue = $queue;
        }
        if (null !== $messageCount) {
            $frame->messageCount = $messageCount;
        }
        if (null !== $consumerCount) {
            $frame->consumerCount = $consumerCount;
        }

        return $frame;
    }
}
