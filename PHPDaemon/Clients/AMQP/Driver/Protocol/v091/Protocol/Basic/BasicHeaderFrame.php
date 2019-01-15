<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\HeaderFrameInterface;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class BasicHeaderFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic
 */
class BasicHeaderFrame implements HeaderFrameInterface, IncomingFrame, OutgoingFrame
{
    public $frameChannelId = 0;
    public $contentLength;
    public $contentType; // shortstr
    public $contentEncoding; // shortstr
    public $headers; // table
    public $deliveryMode; // octet
    public $priority; // octet
    public $correlationId; // shortstr
    public $replyTo; // shortstr
    public $expiration; // shortstr
    public $messageId; // shortstr
    public $timestamp; // timestamp
    public $type; // shortstr
    public $userId; // shortstr
    public $appId; // shortstr
    public $clusterId; // shortstr
}
