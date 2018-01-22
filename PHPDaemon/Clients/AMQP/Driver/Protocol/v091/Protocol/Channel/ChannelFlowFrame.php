<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Channel;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class ChannelFlowFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Channel
 */
class ChannelFlowFrame implements MethodFrame, IncomingFrame, OutgoingFrame
{
    const METHOD_ID = 0x00140014;

    public $frameChannelId = 0;
    public $active; // bit

    public static function create(
        $active = null
    )
    {
        $frame = new self();

        if (null !== $active) {
            $frame->active = $active;
        }

        return $frame;
    }
}
