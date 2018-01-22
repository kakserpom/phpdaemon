<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Tx;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class TxSelectOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Tx
 */
class TxSelectOkFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x005a000b;

    public $frameChannelId = 0;
}
