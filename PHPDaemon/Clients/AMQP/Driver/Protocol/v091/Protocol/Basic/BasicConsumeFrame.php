<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class BasicConsumeFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic
 */
class BasicConsumeFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x003c0014;

    public $frameChannelId = 0;
    public $reserved1 = 0; // short
    public $queue = ''; // shortstr
    public $consumerTag = ''; // shortstr
    public $noLocal = false; // bit
    public $noAck = false; // bit
    public $exclusive = false; // bit
    public $nowait = false; // bit
    public $arguments = []; // table

    public static function create(
        $queue = null, $consumerTag = null, $noLocal = null, $noAck = null, $exclusive = null, $nowait = null, $arguments = null
    )
    {
        $frame = new self();

        if (null !== $queue) {
            $frame->queue = $queue;
        }
        if (null !== $consumerTag) {
            $frame->consumerTag = $consumerTag;
        }
        if (null !== $noLocal) {
            $frame->noLocal = $noLocal;
        }
        if (null !== $noAck) {
            $frame->noAck = $noAck;
        }
        if (null !== $exclusive) {
            $frame->exclusive = $exclusive;
        }
        if (null !== $nowait) {
            $frame->nowait = $nowait;
        }
        if (null !== $arguments) {
            $frame->arguments = $arguments;
        }

        return $frame;
    }
}
