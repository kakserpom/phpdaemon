<?php

namespace PHPDaemon\Clients\AMQP\Driver;

use PHPDaemon\Clients\AMQP\Driver\Protocol\FeaturesInterface;

/**
 * The set of features supported by a broker.
 *
 * @todo add others capabilities
 * @see https://www.rabbitmq.com/amqp-0-9-1-reference.html#connection.start.server-properties
 *
 * Class Features
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver
 */
class Features implements FeaturesInterface
{
    /**
     * @var boolean True if the broker supports per-consumer QoS limits.
     *
     * This feature is indicated by the "per_consumer_qos" capability.
     *
     * @see https://www.rabbitmq.com/consumer-prefetch.html
     */
    public $perConsumerQos = false;

    /**
     * @var boolean True if the server supports QoS size limits.
     *
     * RabbitMQ does not support pre-fetch size limits (current as of v3.5.6).
     */
    public $qosSizeLimits = true;

    /**
     * @var boolean True if the broker supports exchange-to-exchange bindings.
     *
     * This feature is indicated by the "exchange_exchange_bindings" capability.
     *
     * @see https://www.rabbitmq.com/blog/2010/10/19/exchange-to-exchange-bindings/
     */
    public $exchangeToExchangeBindings = false;
}
