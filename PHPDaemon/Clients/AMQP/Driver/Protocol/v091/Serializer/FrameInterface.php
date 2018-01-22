<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Serializer;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Serializes frames to binary data.
 *
 * Interface FrameInterface
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Serializer
 */
interface FrameInterface
{
    /**
     * Serialize a frame, for transmission to the broker.
     *
     * @param OutgoingFrame $frame The frame to serialize.
     *
     * @return string The binary serialized frame.
     */
    public function serialize(OutgoingFrame $frame);
}
