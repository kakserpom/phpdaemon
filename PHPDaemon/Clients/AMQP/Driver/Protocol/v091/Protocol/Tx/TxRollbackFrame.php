<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Tx;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class TxRollbackFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Tx
 */
class TxRollbackFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x005a001e;

    public $frameChannelId = 0;
}
