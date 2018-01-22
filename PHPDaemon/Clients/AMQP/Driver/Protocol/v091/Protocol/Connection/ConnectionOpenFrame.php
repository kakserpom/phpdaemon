<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class ConnectionOpenFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection
 */
class ConnectionOpenFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x000a0028;

    public $frameChannelId = 0;
    public $virtualHost = '/'; // shortstr
    public $capabilities = ''; // shortstr
    public $insist = false; // bit

    public static function create(
        $virtualHost = null, $capabilities = null, $insist = null
    )
    {
        $frame = new self();

        if (null !== $virtualHost) {
            $frame->virtualHost = $virtualHost;
        }
        if (null !== $capabilities) {
            $frame->capabilities = $capabilities;
        }
        if (null !== $insist) {
            $frame->insist = $insist;
        }

        return $frame;
    }
}
