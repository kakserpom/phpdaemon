<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Tx;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class TxCommitOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Tx
 */
class TxCommitOkFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x005a0015;

    public $frameChannelId = 0;
}
