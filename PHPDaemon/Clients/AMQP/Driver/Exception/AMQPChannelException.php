<?php

namespace PHPDaemon\Clients\AMQP\Driver\Exception;

use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPChannelExceptionInterface;
use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPException;

/**
 * Class AMQPChannelException
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Exception
 */
class AMQPChannelException extends AMQPException implements AMQPChannelExceptionInterface
{
}
