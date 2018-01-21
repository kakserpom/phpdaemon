<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Access;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class AccessRequestOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Access
 */
class AccessRequestOkFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x001e000b;

    public $frameChannelId = 0;
    public $reserved1 = 1; // short
}
