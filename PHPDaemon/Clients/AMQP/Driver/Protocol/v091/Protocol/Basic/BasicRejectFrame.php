<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class BasicRejectFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic
 */
class BasicRejectFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x003c005a;

    public $frameChannelId = 0;
    public $deliveryTag; // longlong
    public $requeue = true; // bit

    public static function create(
        $deliveryTag = null, $requeue = null
    )
    {
        $frame = new self();

        if (null !== $deliveryTag) {
            $frame->deliveryTag = $deliveryTag;
        }
        if (null !== $requeue) {
            $frame->requeue = $requeue;
        }

        return $frame;
    }
}
