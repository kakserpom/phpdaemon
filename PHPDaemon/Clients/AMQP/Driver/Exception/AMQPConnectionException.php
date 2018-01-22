<?php

namespace PHPDaemon\Clients\AMQP\Driver\Exception;

use PHPDaemon\Clients\AMQP\Driver\ConnectionOptions;
use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPConnectionExceptionInterface;
use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPException;

/**
 * Class AMQPConnectionException
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Exception
 */
class AMQPConnectionException extends AMQPException implements AMQPConnectionExceptionInterface
{
    /**
     * Create an exception that indicates a failure to establish a connection to
     * an AMQP broker.
     *
     * @param ConnectionOptions $options The options used when establishing the connection.
     * @param string $description A description of the problem.
     * @param \Exception|null $previous The exception that caused this exception, if any.
     * @return AMQPConnectionException
     */
    public static function couldNotConnect(
        ConnectionOptions $options,
        $description,
        \Exception $previous = null
    )
    {
        return new self(
            sprintf(
                'Unable to connect to AMQP broker [%s:%d], check connection options and network connectivity (%s).',
                $options->getHost(),
                $options->getPort(),
                rtrim($description, '.')
            ),
            0,
            $previous
        );
    }

    /**
     * Create an exception that indicates an attempt to use a connection that has
     * already been closed.
     *
     * @param ConnectionOptions $options The options used when establishing the connection.
     * @param \Exception|null $previous The exception that caused this exception, if any.
     * @return AMQPConnectionException
     */
    public static function notOpen(
        ConnectionOptions $options,
        \Exception $previous = null
    )
    {
        return new self(
            sprintf(
                'Unable to use connection to AMQP broker [%s:%d] because it is closed.',
                $options->getHost(),
                $options->getPort()
            ),
            0,
            $previous
        );
    }

    /**
     * Create an exception that indicates that the credentials specified in the
     * connection options are incorrect.
     *
     * @param ConnectionOptions $options The options used when establishing the connection.
     * @param \Exception|null $previous The exception that caused this exception, if any.
     * @return AMQPConnectionException
     */
    public static function authenticationFailed(
        ConnectionOptions $options,
        \Exception $previous = null
    )
    {
        return new self(
            sprintf(
                'Unable to authenticate as "%s" on AMQP broker [%s:%d], check authentication credentials.',
                $options->getUsername(),
                $options->getHost(),
                $options->getPort()
            ),
            0,
            $previous
        );
    }

    /**
     * Create an exception that indicates a the AMQP handshake failed.
     *
     * @param ConnectionOptions $options The options used when establishing the connection.
     * @param string $description A description of the problem.
     * @param \Exception|null $previous The exception that caused this exception, if any.
     * @return AMQPConnectionException
     */
    public static function handshakeFailed(
        ConnectionOptions $options,
        $description,
        \Exception $previous = null
    )
    {
        return new self(
            sprintf(
                'Unable to complete handshake on AMQP broker [%s:%d], %s.',
                $options->getHost(),
                $options->getPort(),
                rtrim($description, '.')
            ),
            0,
            $previous
        );
    }

    /**
     * Create an exception that indicates that the credentials specified in the
     * connection options do not grant access to the requested AMQP virtual host.
     *
     * @param ConnectionOptions $options The options used when establishing the connection.
     * @param \Exception|null $previous The exception that caused this exception, if any.
     * @return AMQPConnectionException
     */
    public static function authorizationFailed(
        ConnectionOptions $options,
        \Exception $previous = null
    )
    {
        return new self(
            sprintf(
                'Unable to access vhost "%s" as "%s" on AMQP broker [%s:%d], check permissions.',
                $options->getVhost(),
                $options->getUsername(),
                $options->getHost(),
                $options->getPort()
            ),
            0,
            $previous
        );
    }

    /**
     * Create an exception that indicates that the broker has failed to send
     * any data for a period longer than the heartbeat interval.
     *
     * @param ConnectionOptions $options The options used when establishing the connection.
     * @param int $heartbeatInterval The heartbeat interval negotiated during the AMQP handshake.
     * @param \Exception|null $previous The exception that caused this exception, if any.
     * @return AMQPConnectionException
     */
    public static function heartbeatTimedOut(
        ConnectionOptions $options,
        $heartbeatInterval,
        \Exception $previous = null
    )
    {
        return new self(
            sprintf(
                'The AMQP connection with broker [%s:%d] has timed out, '
                . 'the last heartbeat was received over %d seconds ago.',
                $options->getHost(),
                $options->getPort(),
                $heartbeatInterval
            ),
            0,
            $previous
        );
    }

    /**
     * Create an exception that indicates an unexpected closure of the
     * connection to the AMQP broker.
     *
     * @param ConnectionOptions $options The options used when establishing the connection.
     * @param \Exception|null $previous The exception that caused this exception, if any.
     * @return AMQPConnectionException
     */
    public static function closedUnexpectedly(
        ConnectionOptions $options,
        \Exception $previous = null
    )
    {
        return new self(
            sprintf(
                'The AMQP connection with broker [%s:%d] was closed unexpectedly.',
                $options->getHost(),
                $options->getPort()
            ),
            0,
            $previous
        );
    }
}
