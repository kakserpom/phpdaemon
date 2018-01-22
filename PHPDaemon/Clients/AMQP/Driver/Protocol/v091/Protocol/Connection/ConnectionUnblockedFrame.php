<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class ConnectionUnblockedFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection
 */
class ConnectionUnblockedFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x000a003d;

    public $frameChannelId = 0;
}
