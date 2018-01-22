<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class ExchangeDeclareFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange
 */
class ExchangeDeclareFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x0028000a;

    public $frameChannelId = 0;
    public $reserved1 = 0; // short
    public $exchange; // shortstr
    public $type = 'direct'; // shortstr
    public $passive = false; // bit
    public $durable = false; // bit
    public $autoDelete = false; // bit
    public $internal = false; // bit
    public $nowait = false; // bit
    public $arguments = []; // table

    public static function create(
        $exchange = null, $type = null, $passive = null, $durable = null, $autoDelete = null, $internal = null, $nowait = null, $arguments = null
    )
    {
        $frame = new self();

        if (null !== $exchange) {
            $frame->exchange = $exchange;
        }
        if (null !== $type) {
            $frame->type = $type;
        }
        if (null !== $passive) {
            $frame->passive = $passive;
        }
        if (null !== $durable) {
            $frame->durable = $durable;
        }
        if (null !== $autoDelete) {
            $frame->autoDelete = $autoDelete;
        }
        if (null !== $internal) {
            $frame->internal = $internal;
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
