<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\Exception;

/**
 * Class AmqpException
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\Exception
 */
class AMQPException extends \RuntimeException implements AMQPExceptionInterface
{
    /**
     * @param string $replyText
     * @param int $replyCode
     * @param \Exception|null $previous
     * @return AMQPException
     */
    public static function create(
        $replyText,
        $replyCode = 0,
        \Exception $previous = null
    )
    {
        return new self(
            $replyText,
            $replyCode,
            $previous
        );
    }
}
