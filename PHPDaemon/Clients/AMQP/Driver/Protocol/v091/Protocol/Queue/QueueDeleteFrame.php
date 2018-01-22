<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class QueueDeleteFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue
 */
class QueueDeleteFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x00320028;

    public $frameChannelId = 0;
    public $reserved1 = 0; // short
    public $queue = ''; // shortstr
    public $ifUnused = false; // bit
    public $ifEmpty = false; // bit
    public $nowait = false; // bit

    public static function create(
        $queue = null, $ifUnused = null, $ifEmpty = null, $nowait = null
    )
    {
        $frame = new self();

        if (null !== $queue) {
            $frame->queue = $queue;
        }
        if (null !== $ifUnused) {
            $frame->ifUnused = $ifUnused;
        }
        if (null !== $ifEmpty) {
            $frame->ifEmpty = $ifEmpty;
        }
        if (null !== $nowait) {
            $frame->nowait = $nowait;
        }

        return $frame;
    }
}
