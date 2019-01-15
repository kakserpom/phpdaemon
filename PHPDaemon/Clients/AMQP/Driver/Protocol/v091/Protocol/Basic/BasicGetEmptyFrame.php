<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class BasicGetEmptyFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic
 */
class BasicGetEmptyFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x003c0048;

    public $frameChannelId = 0;
    public $clusterId = ''; // shortstr

    public static function create(
        $clusterId = null
    )
    {
        $frame = new self();

        if (null !== $clusterId) {
            $frame->clusterId = $clusterId;
        }

        return $frame;
    }
}
