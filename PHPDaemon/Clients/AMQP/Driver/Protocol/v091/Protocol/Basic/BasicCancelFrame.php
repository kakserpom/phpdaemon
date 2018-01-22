<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class BasicCancelFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic
 */
class BasicCancelFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x003c001e;

    public $frameChannelId = 0;
    public $consumerTag; // shortstr
    public $nowait = false; // bit

    public static function create(
        $consumerTag = null, $nowait = null
    )
    {
        $frame = new self();

        if (null !== $consumerTag) {
            $frame->consumerTag = $consumerTag;
        }
        if (null !== $nowait) {
            $frame->nowait = $nowait;
        }

        return $frame;
    }
}
