<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class QueueDeclareFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue
 */
class QueueDeclareFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x0032000a;

    public $frameChannelId = 0;
    public $reserved1 = 0; // short
    public $queue = ''; // shortstr
    public $passive = false; // bit
    public $durable = false; // bit
    public $exclusive = false; // bit
    public $autoDelete = false; // bit
    public $nowait = false; // bit
    public $arguments = []; // table

    public static function create(
        $queue = null, $passive = null, $durable = null, $exclusive = null, $autoDelete = null, $nowait = null, $arguments = null
    )
    {
        $frame = new self();

        if (null !== $queue) {
            $frame->queue = $queue;
        }
        if (null !== $passive) {
            $frame->passive = $passive;
        }
        if (null !== $durable) {
            $frame->durable = $durable;
        }
        if (null !== $exclusive) {
            $frame->exclusive = $exclusive;
        }
        if (null !== $autoDelete) {
            $frame->autoDelete = $autoDelete;
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
