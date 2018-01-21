<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol;

use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPQueueExceptionInterface;

/**
 * An AMQP queue.
 *
 * Messages are routed to queues and then delivered to consumers. Message
 * routing is configured by creating bindings to one or more exchanges.
 *
 * @see QueueInterface::bind() to bind a queue to an exchange.
 *
 * Interface Queue
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol
 */
interface QueueInterface
{
    /**
     * QueueInterface constructor.
     * @param ChannelInterface $channel
     */
    public function __construct(ChannelInterface $channel);

    /**
     * Get the name of the queue.
     *
     * The name may unknown if the queue has not been created.
     */
    public function getName();

    /**
     * Set the name of the queue.
     *
     * @param $name
     * @return QueueInterface
     */
    public function setName($name);

    /**
     * Get the options used to configure queue behaviour.
     * @return QueueOptionsInterface
     */
    public function getOptions();

    /**
     * @param QueueOptionsInterface $options
     * @return QueueInterface
     */
    public function setOptions(QueueOptionsInterface $options);

    /**
     * @param callable $callback
     * @throws AMQPQueueExceptionInterface
     */
    public function declareQueue(callable $callback = null);

    /**
     * @param callable $callback
     */
    public function get(callable $callback = null);

    /**
     * Delete this queue.
     *
     * @throws AMQPQueueExceptionInterface The connection is closed.
     */
    public function deleteQueue();

    /**
     * Bind this queue to an exchange.
     *
     * @param ExchangeInterface|string $exchange The exchange to bind to.
     * @param string $routingKey The routing key for DIRECT and TOPIC exchanges.
     *
     * @throws AMQPQueueExceptionInterface The connection is closed.
     */
    public function bindQueue($exchange, $routingKey = '');

    /**
     * Unbind this queue from an exchange.
     *
     * @param ExchangeInterface|string $exchange The exchange to unbind from.
     * @param string $routingKey The routing key for DIRECT and TOPIC exchanges.
     *
     * @throws AMQPQueueExceptionInterface The connection is closed.
     */
    public function unbindQueue($exchange, $routingKey = '');

    /**
     * Purge the contents of a queue.
     *
     * @throws AMQPQueueExceptionInterface The connection is closed.
     */
    public function purgeQueue();

    /**
     * Get the AMQPChannel object in use
     *
     * @return ChannelInterface
     */
    public function getChannel();

    /**
     * Get the AMQPConnection object in use
     *
     * @return ConnectionInterface
     */
    public function getConnection();
}
