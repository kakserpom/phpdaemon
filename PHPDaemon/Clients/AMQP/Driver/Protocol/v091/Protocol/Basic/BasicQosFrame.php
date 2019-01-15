<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class BasicQosFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic
 */
class BasicQosFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x003c000a;

    public $frameChannelId = 0;
    public $prefetchSize = 0; // long
    public $prefetchCount = 0; // short
    public $global = false; // bit

    public static function create(
        $prefetchSize = null, $prefetchCount = null, $global = null
    )
    {
        $frame = new self();

        if (null !== $prefetchSize) {
            $frame->prefetchSize = $prefetchSize;
        }
        if (null !== $prefetchCount) {
            $frame->prefetchCount = $prefetchCount;
        }
        if (null !== $global) {
            $frame->global = $global;
        }

        return $frame;
    }
}
