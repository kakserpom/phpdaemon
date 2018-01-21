<?php

namespace PHPDaemon\Clients\AMQP;

use PHPDaemon\Clients\AMQP\Driver\Exception\AMQPMessageException;
use PHPDaemon\Clients\AMQP\Driver\Protocol\ExchangeInterface;
use PHPDaemon\Clients\AMQP\Driver\Protocol\QueueInterface;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic\BasicAckFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic\BasicNackFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic\BasicRejectFrame;

/**
 * Class Message
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP
 */
class Message
{

    /**
     * @var int
     */
    protected $contentLength;

    /**
     * @var string
     */
    protected $contentType;

    /**
     * @var string
     */
    protected $contentEncoding;

    /**
     * @var int
     */
    protected $tag;

    /**
     * @var bool
     */
    protected $isDelivered = false;

    /**
     * @var ExchangeInterface
     */
    protected $exchange;

    /**
     * @var string
     */
    protected $routingKey;

    /**
     * @var QueueInterface
     */
    protected $queue;

    /**
     * @var Channel
     */
    protected $channel;

    /**
     * @var array
     */
    protected $headers = [];

    /**
     * @var string
     */
    protected $messageId;

    /**
     * @var int
     */
    protected $deliveryMode;

    /**
     * @var int
     */
    protected $priority;

    /**
     * @var string
     */
    protected $correlationId;

    /**
     * @var string
     */
    protected $replyTo;

    /**
     * @var string
     */
    protected $expiration;

    /**
     * @var int
     */
    protected $timestamp;

    /**
     * @var string
     */
    protected $type;

    /**
     * @var string
     */
    protected $userId;

    /**
     * @var string
     */
    protected $appId;

    /**
     * @var string
     */
    protected $clusterId;

    /**
     * @var string
     */
    protected $content;

    /**
     * Acknowledge the message.
     *
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Exception\AMQPMessageException
     */
    public function ack()
    {
        $this->checkChannel();

        $outputFrame = BasicAckFrame::create($this->tag);
        $outputFrame->frameChannelId = $this->channel->getId();

        $this->channel->getConnection()->command($outputFrame);
    }

    /**
     * Not Acknowledge the message.
     *
     * @param null $multiple
     * @param null $requeue
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function nack($multiple = null, $requeue = null)
    {
        $this->checkChannel();

        $outputFrame = BasicNackFrame::create($this->tag, $multiple, $requeue);
        $outputFrame->frameChannelId = $this->channel->getId();
        $this->channel->getConnection()->command($outputFrame);
    }

    /**
     * Reject the message and requeue it.
     *
     * @see ConsumerOptionsInterface::$noAck to consume messages without requiring
     *      excplicit acknowledgement by the consumer.
     *
     * @param bool $requeue
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Exception\AMQPMessageException
     */
    public function reject($requeue = true)
    {
        $this->checkChannel();

        $outputFrame = BasicRejectFrame::create($this->tag, $requeue);
        $outputFrame->frameChannelId = $this->channel->getId();
        if (null !== $requeue) {
            $outputFrame->requeue = $requeue;
        }
        $this->channel->getConnection()->command($outputFrame);
    }

    /**
     * Get the length of the message content, in bytes.
     * @return int
     */
    public function getContentLength()
    {
        return $this->contentLength;
    }

    /**
     * Set the length of the message content, in bytes.
     * @param int $contentLength
     * @return Message
     */
    public function setContentLength($contentLength)
    {
        $this->contentLength = $contentLength;
        return $this;
    }

    /**
     * @return string
     */
    public function getContentType()
    {
        return $this->contentType;
    }

    /**
     * @param string $contentType
     * @return $this
     */
    public function setContentType($contentType)
    {
        $this->contentType = $contentType;
        return $this;
    }

    /**
     * @return string
     */
    public function getContentEncoding()
    {
        return $this->contentEncoding;
    }

    /**
     * @param string $contentEncoding
     * @return $this
     */
    public function setContentEncoding($contentEncoding)
    {
        $this->contentEncoding = $contentEncoding;
        return $this;
    }

    /**
     * Get the delivery tag.
     * @return int
     */
    public function getTag()
    {
        return $this->tag;
    }

    /**
     * Set the delivery tag.
     * @param int $tag
     * @return Message
     */
    public function setTag($tag)
    {
        $this->tag = $tag;
        return $this;
    }

    /**
     * Check if the message has previously been delivered to a consumer but was
     * implicitly or explicitly rejected.
     * @return bool
     */
    public function isRedelivered()
    {
        return $this->isDelivered;
    }

    /**
     * Get the name of the exchange that the message was published to.
     * @return ExchangeInterface
     */
    public function getExchange()
    {
        return $this->exchange;
    }

    /**
     * Set the name of the exchange that the message was published to.
     * @param ExchangeInterface $exchange
     * @return Message
     */
    public function setExchange($exchange)
    {
        $this->exchange = $exchange;
        return $this;
    }

    /**
     * Get the routing key used when the message was published.
     * @return  string
     */
    public function getRoutingKey()
    {
        return $this->routingKey;
    }

    /**
     * Set the routing key used when the message was published.
     * @param $routingKey
     * @return Message
     */
    public function setRoutingKey($routingKey)
    {
        $this->routingKey = $routingKey;
        return $this;
    }

    /**
     * @return QueueInterface
     */
    public function getQueue()
    {
        return $this->queue;
    }

    /**
     * @param QueueInterface $queue
     * @return $this
     */
    public function setQueue($queue)
    {
        $this->queue = $queue;
        return $this;
    }

    /**
     * @return Channel
     */
    public function getChannel()
    {
        return $this->channel;
    }

    /**
     * @param Channel $channel
     * @return $this
     */
    public function setChannel($channel)
    {
        $this->channel = $channel;
        return $this;
    }

    /**
     * @return string
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * @param string $content
     * @return $this
     */
    public function setContent($content)
    {
        $this->content = $content;
        return $this;
    }

    /**
     * Проеряет, что канал еще жив
     *
     * @throws AMQPMessageException
     */
    private function checkChannel()
    {
        if (!$this->channel->isConnected()) {
            throw new AMQPMessageException('Channel is closed');
        }

        if (null === $this->channel->getId()) {
            throw new AMQPMessageException('AMQPChannel id not found');
        }

        return true;
    }

    /**
     * @return string
     */
    public function getCorrelationId()
    {
        return $this->correlationId;
    }

    /**
     * @param string $correlationId
     * @return $this
     */
    public function setCorrelationId($correlationId)
    {
        $this->correlationId = $correlationId;
        return $this;
    }

    /**
     * @return string
     */
    public function getReplyTo()
    {
        return $this->replyTo;
    }

    /**
     * @param string $replyTo
     * @return $this
     */
    public function setReplyTo($replyTo)
    {
        $this->replyTo = $replyTo;
        return $this;
    }

    /**
     * @return bool
     */
    public function isDelivered()
    {
        return $this->isDelivered;
    }

    /**
     * @param bool $isDelivered
     * @return $this
     */
    public function setIsDelivered($isDelivered)
    {
        $this->isDelivered = $isDelivered;
        return $this;
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @param array $headers
     * @return $this
     */
    public function setHeaders($headers)
    {
        $this->headers = $headers;
        return $this;
    }

    /**
     * @return string
     */
    public function getMessageId()
    {
        return $this->messageId;
    }

    /**
     * @param string $messageId
     * @return $this
     */
    public function setMessageId($messageId)
    {
        $this->messageId = $messageId;
        return $this;
    }

    /**
     * @return int
     */
    public function getDeliveryMode()
    {
        return $this->deliveryMode;
    }

    /**
     * @param int $deliveryMode
     * @return $this
     */
    public function setDeliveryMode($deliveryMode)
    {
        $this->deliveryMode = $deliveryMode;
        return $this;
    }

    /**
     * @return int
     */
    public function getPriority()
    {
        return $this->priority;
    }

    /**
     * @param int $priority
     * @return $this
     */
    public function setPriority($priority)
    {
        $this->priority = $priority;
        return $this;
    }

    /**
     * @return string
     */
    public function getExpiration()
    {
        return $this->expiration;
    }

    /**
     * @param string $expiration
     * @return $this
     */
    public function setExpiration($expiration)
    {
        $this->expiration = $expiration;
        return $this;
    }

    /**
     * @return int
     */
    public function getTimestamp()
    {
        return $this->timestamp;
    }

    /**
     * @param int $timestamp
     * @return $this
     */
    public function setTimestamp($timestamp)
    {
        $this->timestamp = $timestamp;
        return $this;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @param string $type
     * @return $this
     */
    public function setType($type)
    {
        $this->type = $type;
        return $this;
    }

    /**
     * @return string
     */
    public function getUserId()
    {
        return $this->userId;
    }

    /**
     * @param string $userId
     * @return $this
     */
    public function setUserId($userId)
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * @return string
     */
    public function getAppId()
    {
        return $this->appId;
    }

    /**
     * @param string $appId
     * @return $this
     */
    public function setAppId($appId)
    {
        $this->appId = $appId;
        return $this;
    }

    /**
     * @return string
     */
    public function getClusterId()
    {
        return $this->clusterId;
    }

    /**
     * @param string $clusterId
     * @return $this
     */
    public function setClusterId($clusterId)
    {
        $this->clusterId = $clusterId;
        return $this;
    }
}
