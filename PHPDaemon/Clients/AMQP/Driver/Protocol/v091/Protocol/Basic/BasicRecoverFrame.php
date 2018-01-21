<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class BasicRecoverFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic
 */
class BasicRecoverFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x003c006e;

    public $frameChannelId = 0;
    public $requeue = false; // bit

    public static function create(
        $requeue = null
    )
    {
        $frame = new self();

        if (null !== $requeue) {
            $frame->requeue = $requeue;
        }

        return $frame;
    }
}
