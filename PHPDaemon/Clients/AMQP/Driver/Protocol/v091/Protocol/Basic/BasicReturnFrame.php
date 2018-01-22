<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class BasicReturnFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic
 */
class BasicReturnFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x003c0032;

    public $frameChannelId = 0;
    public $replyCode; // short
    public $replyText = ''; // shortstr
    public $exchange; // shortstr
    public $routingKey; // shortstr

    public static function create(
        $replyCode = null, $replyText = null, $exchange = null, $routingKey = null
    )
    {
        $frame = new self();

        if (null !== $replyCode) {
            $frame->replyCode = $replyCode;
        }
        if (null !== $replyText) {
            $frame->replyText = $replyText;
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
