<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Confirm;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class ConfirmSelectOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Confirm
 */
class ConfirmSelectOkFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x0055000b;

    public $frameChannelId = 0;
}
