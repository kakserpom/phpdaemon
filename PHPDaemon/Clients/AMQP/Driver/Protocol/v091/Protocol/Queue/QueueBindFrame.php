<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class QueueBindFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue
 */
class QueueBindFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x00320014;

    public $frameChannelId = 0;
    public $reserved1 = 0; // short
    public $queue = ''; // shortstr
    public $exchange; // shortstr
    public $routingKey = ''; // shortstr
    public $nowait = false; // bit
    public $arguments = []; // table

    public static function create(
        $queue = null, $exchange = null, $routingKey = null, $nowait = null, $arguments = null
    )
    {
        $frame = new self();

        if (null !== $queue) {
            $frame->queue = $queue;
        }
        if (null !== $exchange) {
            $frame->exchange = $exchange;
        }
        if (null !== $routingKey) {
            $frame->routingKey = $routingKey;
        }
        if (null !== $nowait) {
            $frame->nowait = $nowait;
        }
        if (null !== $arguments) {
            $frame->arguments = $arguments;
        }

        return $frame;
    }
}
