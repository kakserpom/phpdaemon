<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class BasicGetFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic
 */
class BasicGetFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x003c0046;

    public $frameChannelId = 0;
    public $reserved1 = 0; // short
    public $queue = ''; // shortstr
    public $noAck = false; // bit

    public static function create(
        $queue = null, $noAck = null
    )
    {
        $frame = new self();

        if (null !== $queue) {
            $frame->queue = $queue;
        }
        if (null !== $noAck) {
            $frame->noAck = $noAck;
        }

        return $frame;
    }
}
