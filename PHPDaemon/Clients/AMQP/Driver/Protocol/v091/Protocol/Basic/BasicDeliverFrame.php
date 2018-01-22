<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\ContentPrecursorFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class BasicDeliverFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic
 */
class BasicDeliverFrame implements MethodFrame, ContentPrecursorFrame, IncomingFrame
{
    const METHOD_ID = 0x003c003c;

    public $frameChannelId = 0;
    public $consumerTag; // shortstr
    public $deliveryTag; // longlong
    public $redelivered = false; // bit
    public $exchange; // shortstr
    public $routingKey; // shortstr

    public static function create(
        $consumerTag = null, $deliveryTag = null, $redelivered = null, $exchange = null, $routingKey = null
    )
    {
        $frame = new self();

        if (null !== $consumerTag) {
            $frame->consumerTag = $consumerTag;
        }
        if (null !== $deliveryTag) {
            $frame->deliveryTag = $deliveryTag;
        }
        if (null !== $redelivered) {
            $frame->redelivered = $redelivered;
        }
        if (null !== $exchange) {
            $frame->exchange = $exchange;
        }
        if (null !== $routingKey) {
            $frame->routingKey = $routingKey;
        }

        return $frame;
    }
}
