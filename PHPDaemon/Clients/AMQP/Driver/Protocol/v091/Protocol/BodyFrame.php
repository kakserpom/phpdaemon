<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol;

/**
 * Class BodyFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol
 */
class BodyFrame implements
    ContentFrameInterface,
    IncomingFrame,
    OutgoingFrame
{
    public $frameChannelId = 0;
    public $content;
}
