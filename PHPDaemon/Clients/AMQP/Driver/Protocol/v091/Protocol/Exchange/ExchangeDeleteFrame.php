<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class ExchangeDeleteFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange
 */
class ExchangeDeleteFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x00280014;

    public $frameChannelId = 0;
    public $reserved1 = 0; // short
    public $exchange; // shortstr
    public $ifUnused = false; // bit
    public $nowait = false; // bit

    public static function create(
        $exchange = null, $ifUnused = null, $nowait = null
    )
    {
        $frame = new self();

        if (null !== $exchange) {
            $frame->exchange = $exchange;
        }
        if (null !== $ifUnused) {
            $frame->ifUnused = $ifUnused;
        }
        if (null !== $nowait) {
            $frame->nowait = $nowait;
        }

        return $frame;
    }
}
