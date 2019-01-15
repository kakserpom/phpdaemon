<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol;

/**
 * Class Constants
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol
 */
class Constants
{
    const FRAME_METHOD = 1;
    const FRAME_HEADER = 2;
    const FRAME_BODY = 3;

    const FRAME_HEARTBEAT = 8;

    const REPLY_SUCCESS = 200;

    const FRAME_END = 206;

    const CONTENT_TOO_LARGE = 311;
    const NO_ROUTE = 312;
    const NO_CONSUMERS = 313;

    const CONNECTION_FORCED = 320;

    const INVALID_PATH = 402;
    const ACCESS_REFUSED = 403;
    const NOT_FOUND = 404;
    const RESOURCE_LOCKED = 405;
    const PRECONDITION_FAILED = 406;

    const FRAME_ERROR = 501;
    const SYNTAX_ERROR = 502;
    const COMMAND_INVALID = 503;
    const CHANNEL_ERROR = 504;
    const UNEXPECTED_FRAME = 505;
    const RESOURCE_ERROR = 506;

    const NOT_ALLOWED = 530;

    const NOT_IMPLEMENTED = 540;
    const INTERNAL_ERROR = 541;

    const FRAME_MIN_SIZE = 4096;
}
