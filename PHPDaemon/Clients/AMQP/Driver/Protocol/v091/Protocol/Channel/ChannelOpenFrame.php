<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Channel;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class ChannelOpenFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Channel
 */
class ChannelOpenFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x0014000a;

    public $frameChannelId = 0;
    public $outOfBand = ''; // shortstr

    public static function create(
        $outOfBand = null
    )
    {
        $frame = new self();

        if (null !== $outOfBand) {
            $frame->outOfBand = $outOfBand;
        }

        return $frame;
    }
}
