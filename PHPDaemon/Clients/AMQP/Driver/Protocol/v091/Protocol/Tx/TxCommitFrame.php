<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Tx;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class TxCommitFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Tx
 */
class TxCommitFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x005a0014;

    public $frameChannelId = 0;
}
