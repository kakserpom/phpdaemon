<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Channel;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class ChannelOpenOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Channel
 */
class ChannelOpenOkFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x0014000b;

    public $frameChannelId = 0;
    public $channelId = ''; // longstr

    public static function create(
        $channelId = null
    )
    {
        $frame = new self();

        if (null !== $channelId) {
            $frame->channelId = $channelId;
        }

        return $frame;
    }
}
