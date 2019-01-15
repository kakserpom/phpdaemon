<?php

namespace PHPDaemon\Clients\AMQP\Driver;

/**
 * Class PackageInfo
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver
 */
class PackageInfo
{
    const NAME = 'PHPDaemon AMQP Client';
    const VERSION = '0.1.0';

    const AMQP_PLATFORM = 'phpd-amqp/' . self::VERSION . '; php/' . PHP_VERSION;
    const AMQP_COPYRIGHT = '(c) 2017, Aleksey I. Kuleshov YOU GLOBAL LIMITED.';
    const AMQP_INFORMATION = self::NAME;
}
