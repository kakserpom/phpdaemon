<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Class ConnectionStartOkFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection
 */
class ConnectionStartOkFrame implements MethodFrame, OutgoingFrame
{
    const METHOD_ID = 0x000a000b;

    public $frameChannelId = 0;
    public $clientProperties = []; // table
    public $mechanism = 'PLAIN'; // shortstr
    public $response; // longstr
    public $locale = 'en_US'; // shortstr

    public static function create(
        $clientProperties = null, $mechanism = null, $response = null, $locale = null
    )
    {
        $frame = new self();

        if (null !== $clientProperties) {
            $frame->clientProperties = $clientProperties;
        }
        if (null !== $mechanism) {
            $frame->mechanism = $mechanism;
        }
        if (null !== $response) {
            $frame->response = $response;
        }
        if (null !== $locale) {
            $frame->locale = $locale;
        }

        return $frame;
    }
}
