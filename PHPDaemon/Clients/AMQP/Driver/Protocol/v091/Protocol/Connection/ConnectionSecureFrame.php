<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class ConnectionSecureFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection
 */
class ConnectionSecureFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x000a0014;

    public $frameChannelId = 0;
    public $challenge; // longstr

    public static function create(
        $challenge = null
    )
    {
        $frame = new self();

        if (null !== $challenge) {
            $frame->challenge = $challenge;
        }

        return $frame;
    }
}
