<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class BasicCancelOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic
 */
class BasicCancelOkFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x003c001f;

    public $frameChannelId = 0;
    public $consumerTag; // shortstr

    public static function create(
        $consumerTag = null
    )
    {
        $frame = new self();

        if (null !== $consumerTag) {
            $frame->consumerTag = $consumerTag;
        }

        return $frame;
    }
}
