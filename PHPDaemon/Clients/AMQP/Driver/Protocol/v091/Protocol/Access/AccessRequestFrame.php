<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Access;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class AccessRequestFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Access
 */
class AccessRequestFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x001e000a;

    public $frameChannelId = 0;
    public $realm = '/data'; // shortstr
    public $exclusive = false; // bit
    public $passive = true; // bit
    public $active = true; // bit
    public $write = true; // bit
    public $read = true; // bit

    public static function create(
        $realm = null, $exclusive = null, $passive = null, $active = null, $write = null, $read = null
    )
    {
        $frame = new self();

        if (null !== $realm) {
            $frame->realm = $realm;
        }
        if (null !== $exclusive) {
            $frame->exclusive = $exclusive;
        }
        if (null !== $passive) {
            $frame->passive = $passive;
        }
        if (null !== $active) {
            $frame->active = $active;
        }
        if (null !== $write) {
            $frame->write = $write;
        }
        if (null !== $read) {
            $frame->read = $read;
        }

        return $frame;
    }
}
