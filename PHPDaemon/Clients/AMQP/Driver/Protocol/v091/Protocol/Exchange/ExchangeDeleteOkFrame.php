<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class ExchangeDeleteOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange
 */
class ExchangeDeleteOkFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x00280015;

    public $frameChannelId = 0;
}
