<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol;

/**
 * The heartbeat frame is sent by both the client and the broker in order to
 * keep the connection alive.
 *
 * @see Constants::FRAME_HEARTBEAT
 *
 * Class HeartbeatFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol
 */
class HeartbeatFrame implements IncomingFrame, OutgoingFrame
{
    /**
     * @var integer Heartbeat frames are only sent on channel zero.
     */
    public $frameChannelId = 0;
}
