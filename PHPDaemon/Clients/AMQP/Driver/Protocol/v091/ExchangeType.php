<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091;

/**
 * An exchange's type controls how queue bindings are used to route messages.
 *
 * @see Connection::exchange() to declare an exchange.
 * @see QueueInterface::bind() to bind a queue to an exchange.
 * @see https://www.rabbitmq.com/tutorials/amqp-concepts.html#exchanges
 */
class ExchangeType
{
    /**
     * Route messages to all bindings with a routing key that matches exactly
     * the routing key of the message.
     */
    const DIRECT = 'direct';

    /**
     * Route messages to all bindings. The routing key is ignored.
     */
    const FANOUT = 'fanout';

    /**
     * Route messages to all bindings with a routing key whose pattern matches
     * the routing key of the messages.
     */
    const TOPIC = 'topic';

    /**
     * Route messages to all bindings with headers that match the message
     * headers. The routing key is ignored.
     */
    const HEADERS = 'headers';

    /**
     * Check whether this exchange type requires a routing key when publishing
     * a message.
     */
    public static function requiresRoutingKey()
    {
        return [self::DIRECT, self::TOPIC];
    }
}
