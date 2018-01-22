<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\ContentPrecursorFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class BasicPublishFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic
 */
class BasicPublishFrame implements MethodFrame, ContentPrecursorFrame, OutgoingFrame
{
    const METHOD_ID = 0x003c0028;

    public $frameChannelId = 0;
    public $reserved1 = 0; // short
    public $exchange = ''; // shortstr
    public $routingKey = ''; // shortstr
    public $mandatory = false; // bit
    public $immediate = false; // bit

    public static function create(
        $exchange = null, $routingKey = null, $mandatory = null, $immediate = null
    )
    {
        $frame = new self();

        if (null !== $exchange) {
            $frame->exchange = $exchange;
        }
        if (null !== $routingKey) {
            $frame->routingKey = $routingKey;
        }
        if (null !== $mandatory) {
            $frame->mandatory = $mandatory;
        }
        if (null !== $immediate) {
            $frame->immediate = $immediate;
        }

        return $frame;
    }
}
