<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Channel;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class ChannelCloseOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Channel
 */
class ChannelCloseOkFrame implements MethodFrame, IncomingFrame, OutgoingFrame
{
    const METHOD_ID = 0x00140029;

    public $frameChannelId = 0;
}
