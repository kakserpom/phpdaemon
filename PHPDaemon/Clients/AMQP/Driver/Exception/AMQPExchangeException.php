<?php

namespace PHPDaemon\Clients\AMQP\Driver\Exception;

use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPException;
use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPExchangeExceptionInterface;

/**
 * Class AMQPExchangeException
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Exception
 */
class AMQPExchangeException extends AMQPException implements AMQPExchangeExceptionInterface
{
}
