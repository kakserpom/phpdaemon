<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Channel;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class ChannelCloseFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Channel
 */
class ChannelCloseFrame implements MethodFrame, IncomingFrame, OutgoingFrame
{
    const METHOD_ID = 0x00140028;

    public $frameChannelId = 0;
    public $replyCode; // short
    public $replyText = ''; // shortstr
    public $classId; // short
    public $methodId; // short

    public static function create(
        $replyCode = null, $replyText = null, $classId = null, $methodId = null
    )
    {
        $frame = new self();

        if (null !== $replyCode) {
            $frame->replyCode = $replyCode;
        }
        if (null !== $replyText) {
            $frame->replyText = $replyText;
        }
        if (null !== $classId) {
            $frame->classId = $classId;
        }
        if (null !== $methodId) {
            $frame->methodId = $methodId;
        }

        return $frame;
    }
}
