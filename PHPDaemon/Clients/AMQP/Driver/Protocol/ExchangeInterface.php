<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol;

/**
 * An AMQP exchange.
 *
 * All messages are published to an exchange, and then routed to zero or more
 * queues based on the queue bindings.
 *
 * @see QueueInterface::bind() to bind a queue to an exchange.
 * @see ExchangeInterface::bind() to bind one exchange to another.
 */
interface ExchangeInterface
{
    /**
     * ExchangeInterface constructor.
     * @param ChannelInterface $channel
     */
    public function __construct(ChannelInterface $channel);

    /**
     * Get the name of the exchange.
     * @return string
     */
    public function getName();

    /**
     * Set the name of the exchange.
     *
     * @param $name
     * @return ExchangeInterface
     */
    public function setName($name);

    /**
     * Get the exchange type.
     * @return string
     */
    public function getType();

    /**
     * Set the exchange type.
     * @param string $type
     * @return ExchangeInterface
     */
    public function setType($type);

    /**
     * Get the options used to configure exchange behaviour.
     * @return ExchangeOptionsInterface
     */
    public function getOptions();

    /**
     * Регистрирует обменник в брокере
     *
     * @param callable|\Closure|null $callback
     */
    public function declareExchange(callable $callback = null);

    /**
     * Delete this exchange.
     */
    public function deleteExchange();

    /**
     * Bind this exchange to another.
     *
     * This feature requires the "exchange_exchange_bindings" broker capability.
     *
     * @param ExchangeInterface|string $exchangeName
     * @param string $routingKey
     * @param array $arguments
     */
    public function bindExchange($exchangeName, $routingKey = '', array $arguments = []);

    /**
     * Unbind this exchange from another.
     *
     * This feature requires the "exchange_exchange_bindings" broker capability.
     *
     * @param ExchangeInterface|string $exchangeName
     * @param string $routingKey
     * @param array $arguments
     */
    public function unbindExchange($exchangeName, $routingKey = '', array $arguments = []);
}
