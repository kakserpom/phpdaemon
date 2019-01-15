<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class ConnectionSecureOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection
 */
class ConnectionSecureOkFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x000a0015;

    public $frameChannelId = 0;
    public $response; // longstr

    public static function create(
        $response = null
    )
    {
        $frame = new self();

        if (null !== $response) {
            $frame->response = $response;
        }

        return $frame;
    }
}
