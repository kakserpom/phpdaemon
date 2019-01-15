<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol;

use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPMessageExceptionInterface;

/**
 * A message interface.
 *
 * Interface MessageInterface
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol
 */
interface MessageInterface
{
    /**
     * Acknowledge the message.
     *
     * @see ConsumerOptionsInterface::$noAck to consume messages without requiring
     *      excplicit acknowledgement by the consumer.
     *
     * @throws AMQPMessageExceptionInterface The connection is closed.
     */
    public function ack();

    /**
     * Reject the message and requeue it.
     *
     * @see ConsumerOptionsInterface::$noAck to consume messages without requiring
     *      excplicit acknowledgement by the consumer.
     *
     * @param null|bool $requeue
     */
    public function reject($requeue = null);

    /**
     * Reject the message without requeing it.
     *
     * The broker may discard the message outright, or deliver it to a
     * dead-letter queue, depending on configuration.
     *
     * @see ConsumerOptionsInterface::$noAck to consume messages without requiring
     *      excplicit acknowledgement by the consumer.
     *
     * @throws AMQPMessageExceptionInterface The connection is closed.
     */
    public function discard();

    /**
     * Get the length of the message content, in bytes.
     * @return int
     */
    public function getContentLength();

    /**
     * Set the length of the message content, in bytes.
     * @param int $contentLength
     * @return MessageInterface
     */
    public function setContentLength($contentLength);

    /**
     * Get the delivery tag.
     * @return int
     */
    public function getTag();

    /**
     * Set the delivery tag.
     * @param int $tag
     * @return MessageInterface
     */
    public function setTag($tag);

    /**
     * Check if the message has previously been delivered to a consumer but was
     * implicitly or explicitly rejected.
     * @return bool
     */
    public function isRedelivered();

    /**
     * Get the name of the exchange that the message was published to.
     * @return ExchangeInterface
     */
    public function getExchange();

    /**
     * Set the name of the exchange that the message was published to.
     * @param ExchangeInterface $exchange
     * @return MessageInterface
     */
    public function setExchange($exchange);

    /**
     * Get the routing key used when the message was published.
     * @return  string
     */
    public function getRoutingKey();

    /**
     * Set the routing key used when the message was published.
     * @param $routingKey
     * @return MessageInterface
     */
    public function setRoutingKey($routingKey);

    /**
     * @return QueueInterface
     */
    public function getQueue();

    /**
     * @param QueueInterface $queue
     * @return $this
     */
    public function setQueue($queue);

    /**
     * @return ChannelInterface
     */
    public function getChannel();

    /**
     * @param ChannelInterface $channel
     * @return $this
     */
    public function setChannel($channel);
}
