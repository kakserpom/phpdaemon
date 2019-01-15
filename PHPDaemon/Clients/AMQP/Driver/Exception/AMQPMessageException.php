<?php

namespace PHPDaemon\Clients\AMQP\Driver\Exception;

use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPException;
use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPMessageExceptionInterface;

/**
 * Class AMQPMessageException
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Exception
 */
class AMQPMessageException extends AMQPException implements AMQPMessageExceptionInterface
{
}
