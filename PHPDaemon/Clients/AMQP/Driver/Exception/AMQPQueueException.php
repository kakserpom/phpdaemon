<?php

namespace PHPDaemon\Clients\AMQP\Driver\Exception;

use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPException;
use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPQueueExceptionInterface;

/**
 * Class AMQPQueueException
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Exception
 */
class AMQPQueueException extends AMQPException implements AMQPQueueExceptionInterface
{
}
