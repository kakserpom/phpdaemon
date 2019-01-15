<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\ContentPrecursorFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class BasicGetOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic
 */
class BasicGetOkFrame implements MethodFrame, ContentPrecursorFrame, IncomingFrame
{
    const METHOD_ID = 0x003c0047;

    public $frameChannelId = 0;
    public $deliveryTag; // longlong
    public $redelivered = false; // bit
    public $exchange; // shortstr
    public $routingKey; // shortstr
    public $messageCount; // long

    public static function create(
        $deliveryTag = null, $redelivered = null, $exchange = null, $routingKey = null, $messageCount = null
    )
    {
        $frame = new self();

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
        if (null !== $messageCount) {
            $frame->messageCount = $messageCount;
        }

        return $frame;
    }
}
