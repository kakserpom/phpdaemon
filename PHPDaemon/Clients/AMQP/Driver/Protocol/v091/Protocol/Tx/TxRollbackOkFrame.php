<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Tx;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class TxRollbackOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Tx
 */
class TxRollbackOkFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x005a001f;

    public $frameChannelId = 0;
}
