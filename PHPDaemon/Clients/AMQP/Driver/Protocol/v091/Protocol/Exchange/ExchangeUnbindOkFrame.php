<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class ExchangeUnbindOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange
 */
class ExchangeUnbindOkFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x00280033;

    public $frameChannelId = 0;
}
