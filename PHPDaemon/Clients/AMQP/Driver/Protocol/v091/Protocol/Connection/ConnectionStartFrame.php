<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\MethodFrame;

/**
 * Class ConnectionStartFrame
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection
 */
class ConnectionStartFrame implements MethodFrame, IncomingFrame
{
    const METHOD_ID = 0x000a000a;

    public $frameChannelId = 0;
    public $versionMajor = 0; // octet
    public $versionMinor = 9; // octet
    public $serverProperties = []; // table
    public $mechanisms = 'PLAIN'; // longstr
    public $locales = 'en_US'; // longstr

    public static function create(
        $versionMajor = null, $versionMinor = null, $serverProperties = null, $mechanisms = null, $locales = null
    )
    {
        $frame = new self();

        if (null !== $versionMajor) {
            $frame->versionMajor = $versionMajor;
        }
        if (null !== $versionMinor) {
            $frame->versionMinor = $versionMinor;
        }
        if (null !== $serverProperties) {
            $frame->serverProperties = $serverProperties;
        }
        if (null !== $mechanisms) {
            $frame->mechanisms = $mechanisms;
        }
        if (null !== $locales) {
            $frame->locales = $locales;
        }

        return $frame;
    }
}
