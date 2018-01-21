<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class ExchangeBindFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange
 */
class ExchangeBindFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x0028001e;

    public $frameChannelId = 0;
    public $reserved1 = 0; // short
    public $destination; // shortstr
    public $source; // shortstr
    public $routingKey = ''; // shortstr
    public $nowait = false; // bit
    public $arguments = []; // table

    public static function create(
        $destination = null, $source = null, $routingKey = null, $nowait = null, $arguments = null
    )
    {
        $frame = new self();

        if (null !== $destination) {
            $frame->destination = $destination;
        }
        if (null !== $source) {
            $frame->source = $source;
        }
        if (null !== $routingKey) {
            $frame->routingKey = $routingKey;
        }
        if (null !== $nowait) {
            $frame->nowait = $nowait;
        }
        if (null !== $arguments) {
            $frame->arguments = $arguments;
        }

        return $frame;
    }
}
