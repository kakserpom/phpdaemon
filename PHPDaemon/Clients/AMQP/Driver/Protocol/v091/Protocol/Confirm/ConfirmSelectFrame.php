<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Confirm;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class ConfirmSelectFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Confirm
 */
class ConfirmSelectFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x0055000a;

    public $frameChannelId = 0;
    public $nowait = false; // bit

    public static function create(
        $nowait = null
    )
    {
        $frame = new self();

        if (null !== $nowait) {
            $frame->nowait = $nowait;
        }

        return $frame;
    }
}
