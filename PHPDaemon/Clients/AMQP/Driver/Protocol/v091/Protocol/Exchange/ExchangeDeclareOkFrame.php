<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class ExchangeDeclareOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange
 */
class ExchangeDeclareOkFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x0028000b;

    public $frameChannelId = 0;
}
