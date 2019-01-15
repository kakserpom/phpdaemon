<?php

namespace PHPDaemon\Clients\AMQP;

use PHPDaemon\Clients\AMQP\Driver\Exception\AMQPChannelException;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\BodyFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Channel as ProtocolChannel;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue;
use PHPDaemon\Core\Daemon;
use PHPDaemon\Traits\EventHandlers;

/**
 * Class Channel
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP
 */
class Channel
{
    use EventHandlers;

    /**
     * The QOS prefetch count value
     */
    const QOS_PREFETCH_COUNT = 3;

    /**
     * The QOS prefetch size value
     */
    const QOS_PREFECTH_SIZE = 0;

    /**
     * This event raised on channel open
     */
    const EVENT_ON_CHANNEL_OPEN_CALLBACK = 'event.on.channel.open.callback';

    /**
     *  This event raised on channel close
     */
    const EVENT_ON_CHANNEL_CLOSE_CALLBACK = 'event.on.channel.close.callback';

    /**
     * This event raised on channel consume ok frame
     */
    const EVENT_ON_CHANNEL_CONSUMEOK_CALLBACK = 'event.on.channel.consumeOk.callback';

    /**
     * This event raised on queue declare confirmation
     */
    const EVENT_ON_CHANNEL_DECLARE_QUEUE_CALLBACK = 'event.channel.dispatch.declareQueue.callback';

    /**
     * This event raised on queue delete confirmation
     */
    const EVENT_ON_CHANNEL_DELETE_QUEUE_CALLBACK = 'event.on.channel.deleteQueue.callback';

    /**
     * This event raised on queue purge confirmation
     */
    const EVENT_ON_CHANNEL_PURGE_QUEUE_CALLBACK = 'event.on.channel.purgeQueue.callback';

    /**
     * This event raised on queue bind confirmation
     */
    const EVENT_ON_CHANNEL_BIND_QUEUE_CALLBACK = 'event.on.channel.bindQueue.callback';

    /**
     * This event raised on queue unbind confirmation
     */
    const EVENT_ON_CHANNEL_UNBIND_QUEUE_CALLBACK = 'event.on.channel.unbindQueue.callback';

    /**
     * This event raised on BasicGet message income
     */
    const EVENT_DISPATCH_MESSAGE = 'event.channel.dispatch.message';

    /**
     * This event raised on BasicConsume message income
     */
    const EVENT_DISPATCH_CONSUMER_MESSAGE = 'event.channel.dispatch.consumer.message';

    /**
     * This event raised on exchange declare confirmation
     */
    const EVENT_ON_CHANNEL_DECLARE_EXCHANGE_CALLBACK = 'event.channel.dispatch.declareExchange.callback';

    /**
     * This event raised on exchange delete confirmation
     */
    const EVENT_ON_CHANNEL_DELETE_EXCHANGE_CALLBACK = 'event.channel.dispatch.deleteExchange.callback';

    /**
     * This event raised on exchange bind confirmation
     */
    const EVENT_ON_CHANNEL_BIND_EXCHANGE_CALLBACK = 'event.channel.dispatch.bindExchange.callback';

    /**
     * This event raised on exchange unbind confirmation
     */
    const EVENT_ON_CHANNEL_UNBIND_EXCHANGE_CALLBACK = 'event.channel.dispatch.unbindExchange.callback';

    /**
     * @var Connection
     */
    protected $connection;

    /**
     * @var int
     */
    protected $id;

    /**
     * @var array
     */
    protected $consumers = [];

    /**
     * @var
     */
    private $stack;

    /**
     * @var bool
     */
    private $isConnected;

    /**
     * AMQPChannel constructor.
     * @param Connection $connection
     * @param callable|null $callback
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Exception\AMQPConnectionException
     */
    public function __construct(Connection $connection, callable $callback = null)
    {
        $this->connection = $connection;
        $this->addThisToEvents = false;

        $outputFrame = new ProtocolChannel\ChannelOpenFrame();
        $outputFrame->frameChannelId = $this->connection->findChannelId();
        $this->connection->addChannel($outputFrame->frameChannelId, $this);

        $this->connection->command($outputFrame);

        $this->on(ProtocolChannel\ChannelOpenOkFrame::class, [$this, 'dispatch']);
        $this->on(ProtocolChannel\ChannelCloseFrame::class, [$this, 'dispatch']);
        $this->on(ProtocolChannel\ChannelCloseOkFrame::class, [$this, 'dispatch']);

        $this->on(Basic\BasicQosOkFrame::class, [$this, 'dispatch']);
        $this->on(Basic\BasicCancelOkFrame::class, [$this, 'dispatch']);
        $this->on(Basic\BasicDeliverFrame::class, [$this, 'dispatch']);
        $this->on(Basic\BasicGetOkFrame::class, [$this, 'dispatch']);
        $this->on(Basic\BasicHeaderFrame::class, [$this, 'dispatch']);
        $this->on(Basic\BasicGetEmptyFrame::class, [$this, 'dispatch']);
        $this->on(Basic\BasicConsumeOkFrame::class, [$this, 'dispatch']);

        $this->on(BodyFrame::class, [$this, 'dispatch']);

        $this->on(Queue\QueueDeclareOkFrame::class, [$this, 'dispatch']);
        $this->on(Queue\QueueDeleteOkFrame::class, [$this, 'dispatch']);
        $this->on(Queue\QueuePurgeOkFrame::class, [$this, 'dispatch']);
        $this->on(Queue\QueueBindOkFrame::class, [$this, 'dispatch']);
        $this->on(Queue\QueueUnbindOkFrame::class, [$this, 'dispatch']);

        $this->on(Exchange\ExchangeDeclareOkFrame::class, [$this, 'dispatch']);
        $this->on(Exchange\ExchangeDeleteOkFrame::class, [$this, 'dispatch']);
        $this->on(Exchange\ExchangeBindOkFrame::class, [$this, 'dispatch']);
        $this->on(Exchange\ExchangeUnbindOkFrame::class, [$this, 'dispatch']);

        $this->on(self::EVENT_DISPATCH_CONSUMER_MESSAGE, function ($consumerTag, $message) {
            if (array_key_exists($consumerTag, $this->consumers)) {
                $consumer = $this->consumers[$consumerTag];
                $consumer($message);
                return;
            }
        });

        $this->on(self::EVENT_ON_CHANNEL_OPEN_CALLBACK, function ($channel) use ($callback) {
            if (is_callable($callback)) {
                $callback($channel);
            }
        });

        $this->stack = new \SplObjectStorage();
    }

    /**
     * Dispatch incoming frame
     * @param IncomingFrame $incomingFrame
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function dispatch(IncomingFrame $incomingFrame)
    {
        switch (true) {
            case $incomingFrame instanceof Basic\BasicDeliverFrame:
            case $incomingFrame instanceof Basic\BasicGetOkFrame:
                $message = new Message();
                $message->setRoutingKey($incomingFrame->routingKey)
                    ->setExchange($incomingFrame->exchange)
                    ->setTag($incomingFrame->deliveryTag)
                    ->setChannel($this);
                $object = new \stdClass();
                if ($incomingFrame instanceof Basic\BasicDeliverFrame) {
                    $object->consumerTag = $incomingFrame->consumerTag;
                }
                $object->message = $message;
                $this->stack->attach($object);
                $this->stack->rewind();
                break;
            case $incomingFrame instanceof Basic\BasicHeaderFrame:
                $object = $this->stack->current();
                /** @var Message $message */
                $message = $object->message;
                $message->setContentLength($incomingFrame->contentLength)
                    ->setContentType($incomingFrame->contentType)
                    ->setContentEncoding($incomingFrame->contentEncoding)
                    ->setHeaders($incomingFrame->headers)
                    ->setMessageId($incomingFrame->messageId)
                    ->setDeliveryMode($incomingFrame->deliveryMode)
                    ->setCorrelationId($incomingFrame->correlationId)
                    ->setReplyTo($incomingFrame->replyTo)
                    ->setExpiration($incomingFrame->expiration)
                    ->setTimestamp($incomingFrame->timestamp)
                    ->setType($incomingFrame->type)
                    ->setUserId($incomingFrame->userId)
                    ->setAppId($incomingFrame->appId)
                    ->setClusterId($incomingFrame->clusterId);
                $object->totalPayloadSize = $incomingFrame->contentLength;
                break;
            case $incomingFrame instanceof BodyFrame:
                $object = $this->stack->current();
                /**
                 * Use only php strlen because wee need string length in bytes
                 */
                $currentContentLength = strlen($incomingFrame->content);

                /** @var Message $message */
                $message = $object->message;
                $message->setContent($message->getContent() . $incomingFrame->content);

                $object->totalPayloadSize -= $currentContentLength;

                if ($object->totalPayloadSize === 0) {
                    if (isset($object->consumerTag)) {
                        $this->trigger(self::EVENT_DISPATCH_CONSUMER_MESSAGE, $object->consumerTag, $message);
                    } else {
                        $this->triggerOneAndUnbind(self::EVENT_DISPATCH_MESSAGE, $message);
                    }
                    $this->stack->detach($object);
                }
                break;
            case $incomingFrame instanceof Basic\BasicGetEmptyFrame:
                $this->triggerOneAndUnbind(self::EVENT_DISPATCH_MESSAGE, false);
                break;
            case $incomingFrame instanceof Basic\BasicCancelOkFrame:
                unset($this->consumers[$incomingFrame->consumerTag]);
                break;

            case $incomingFrame instanceof ProtocolChannel\ChannelOpenOkFrame:
                $this->isConnected = true;
                $this->id = $incomingFrame->frameChannelId;
                //write QoS
                $outputFrame = Basic\BasicQosFrame::create(
                    self::QOS_PREFECTH_SIZE,
                    self::QOS_PREFETCH_COUNT
                );
                $outputFrame->frameChannelId = $incomingFrame->frameChannelId;
                $this->connection->command($outputFrame);
                break;
            case $incomingFrame instanceof ProtocolChannel\ChannelCloseFrame:
                $this->trigger(self::EVENT_ON_CHANNEL_CLOSE_CALLBACK);
                Daemon::log(sprintf('[AMQP] Channel closed by broker. Reason: %s[%d]', $incomingFrame->replyText, $incomingFrame->replyCode));
                $this->isConnected = false;
                break;
            case $incomingFrame instanceof Basic\BasicQosOkFrame:
                $this->trigger(self::EVENT_ON_CHANNEL_OPEN_CALLBACK, $this);
                break;
            case $incomingFrame instanceof Basic\BasicConsumeOkFrame:
                $this->triggerOneAndUnbind(self::EVENT_ON_CHANNEL_CONSUMEOK_CALLBACK, $incomingFrame);
                break;
            case $incomingFrame instanceof ProtocolChannel\ChannelCloseOkFrame:
                $this->isConnected = false;
                break;
            case $incomingFrame instanceof Queue\QueueDeclareOkFrame:
                $this->triggerOneAndUnbind(self::EVENT_ON_CHANNEL_DECLARE_QUEUE_CALLBACK);
                break;
            case $incomingFrame instanceof Queue\QueueDeleteOkFrame:
                $this->triggerOneAndUnbind(self::EVENT_ON_CHANNEL_DELETE_QUEUE_CALLBACK);
                break;
            case $incomingFrame instanceof Queue\QueuePurgeOkFrame:
                $this->triggerOneAndUnbind(self::EVENT_ON_CHANNEL_PURGE_QUEUE_CALLBACK);
                break;
            case $incomingFrame instanceof Queue\QueueBindOkFrame:
                $this->triggerOneAndUnbind(self::EVENT_ON_CHANNEL_BIND_QUEUE_CALLBACK);
                break;
            case $incomingFrame instanceof Queue\QueueUnbindOkFrame:
                $this->triggerOneAndUnbind(self::EVENT_ON_CHANNEL_UNBIND_QUEUE_CALLBACK);
                break;
            case $incomingFrame instanceof Exchange\ExchangeDeclareOkFrame:
                $this->triggerOneAndUnbind(self::EVENT_ON_CHANNEL_DECLARE_EXCHANGE_CALLBACK);
                break;
            case $incomingFrame instanceof Exchange\ExchangeDeleteOkFrame:
                $this->triggerOneAndUnbind(self::EVENT_ON_CHANNEL_DELETE_EXCHANGE_CALLBACK);
                break;
            case $incomingFrame instanceof Exchange\ExchangeBindOkFrame:
                $this->triggerOneAndUnbind(self::EVENT_ON_CHANNEL_BIND_EXCHANGE_CALLBACK);
                break;
            case $incomingFrame instanceof Exchange\ExchangeUnbindOkFrame:
                $this->triggerOneAndUnbind(self::EVENT_ON_CHANNEL_UNBIND_EXCHANGE_CALLBACK);
                break;
        }
    }

    /**
     * Close the channel.
     *
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function close()
    {
        $outputFrame = ProtocolChannel\ChannelCloseFrame::create(
            0,//@todo replyCode
            'Channel closed by client'
        );
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);
    }

    /**
     * Check queue
     *
     * @param $name
     * @param array $options
     * @param callable|null $callback
     */
    public function checkQueue($name, array $options = [], callable $callback = null)
    {
        //@todo implement this
    }

    /**
     * DeclareQueue
     *
     * @param string $name a quque name
     * @param array $options a queue options
     * @param callable|null $callback
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function declareQueue($name, array $options = [], callable $callback = null)
    {
        $passive = array_key_exists('passive', $options) ? (bool)$options['passive'] : null;
        $durable = array_key_exists('durable', $options) ? (bool)$options['durable'] : null;
        $exclusive = array_key_exists('exclusive', $options) ? (bool)$options['exclusive'] : null;
        $autoDelete = array_key_exists('autoDelete', $options) ? (bool)$options['autoDelete'] : null;
        $noWait = array_key_exists('noWait', $options) ? (bool)$options['noWait'] : null;
        $arguments = array_key_exists('arguments', $options) ? $options['arguments'] : null;

        $outputFrame = Queue\QueueDeclareFrame::create(
            $name,
            $passive,
            $durable,
            $exclusive,
            $autoDelete,
            $noWait,
            $arguments
        );
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);

        if (is_callable($callback)) {
            $this->on(self::EVENT_ON_CHANNEL_DECLARE_QUEUE_CALLBACK, $callback);
        }
    }

    /**
     * Delete Queue
     *
     * @param string $name a queue name
     * @param array $options a options array
     * @param callable|null $callback
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function deleteQueue($name, array $options = [], callable $callback = null)
    {
        $ifUnused = array_key_exists('ifUnused', $options) ? (bool)$options['ifUnused'] : null;
        $ifEmpty = array_key_exists('ifEmpty', $options) ? (bool)$options['ifEmpty'] : null;
        $noWait = array_key_exists('noWait', $options) ? (bool)$options['noWait'] : null;

        $outputFrame = Queue\QueueDeleteFrame::create($name, $ifUnused, $ifEmpty, $noWait);
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);

        if (is_callable($callback)) {
            $this->on(self::EVENT_ON_CHANNEL_DELETE_QUEUE_CALLBACK, $callback);
        }
    }

    /**
     * Purge queue messages
     *
     * @param string $name a queue name
     * @param array $options a options array
     * @param callable|null $callback
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function purgeQueue($name, array $options = [], callable $callback = null)
    {
        $noWait = array_key_exists('noWait', $options) ? (bool)$options['noWait'] : null;

        $outputFrame = Queue\QueuePurgeFrame::create($name, $noWait);
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);

        if (is_callable($callback)) {
            $this->on(self::EVENT_ON_CHANNEL_PURGE_QUEUE_CALLBACK, $callback);
        }
    }

    /**
     * Bind queue to exchange
     *
     * @param string $name a queue name
     * @param string $exchangeName a exchange name
     * @param string $routingKey a routing key
     * @param array $options additional options
     * @param callable|null $callback
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function bindQueue($name, $exchangeName, $routingKey, array $options = [], callable $callback = null)
    {
        $noWait = array_key_exists('noWait', $options) ? (bool)$options['noWait'] : null;
        $arguments = array_key_exists('arguments', $options) ? $options['arguments'] : null;

        $outputFrame = Queue\QueueBindFrame::create(
            $name,
            $exchangeName,
            $routingKey,
            $noWait,
            $arguments
        );
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);

        if (is_callable($callback)) {
            $this->on(self::EVENT_ON_CHANNEL_BIND_QUEUE_CALLBACK, $callback);
        }
    }

    /**
     * Unbind queue from exchange
     *
     * @param string $name a queue name
     * @param string $exchangeName a exchange name
     * @param string $routingKey a routing key
     * @param callable|null $callback
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function unbindQueue($name, $exchangeName, $routingKey, callable $callback = null)
    {
        $outputFrame = Queue\QueueUnbindFrame::create(
            $name,
            $exchangeName,
            $routingKey
        );
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);

        if (is_callable($callback)) {
            $this->on(self::EVENT_ON_CHANNEL_UNBIND_QUEUE_CALLBACK, $callback);
        }
    }

    /**
     * Declare exchange
     *
     * @param string $name a name of exchange
     * @param array $options exchange options
     * @param callable|null $callback
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function declareExchange($name, array $options = [], callable $callback = null)
    {
        $type = array_key_exists('type', $options) ? $options['type'] : null;
        $passive = array_key_exists('passive', $options) ? (bool)$options['passive'] : null;
        $durable = array_key_exists('durable', $options) ? (bool)$options['durable'] : null;
        $internal = array_key_exists('internal', $options) ? (bool)$options['internal'] : null;
        $autoDelete = array_key_exists('autoDelete', $options) ? (bool)$options['autoDelete'] : null;
        $noWait = array_key_exists('noWait', $options) ? (bool)$options['noWait'] : null;
        $arguments = array_key_exists('arguments', $options) ? $options['arguments'] : null;

        $outputFrame = Exchange\ExchangeDeclareFrame::create(
            $name,
            $type,
            $passive,
            $durable,
            $autoDelete,
            $internal,
            $noWait,
            $arguments
        );
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);

        if (is_callable($callback)) {
            $this->on(self::EVENT_ON_CHANNEL_DECLARE_EXCHANGE_CALLBACK, $callback);
        }
    }

    /**
     * Delete Exchange
     *
     * @param string $name a exchange name
     * @param array $options a exchange options
     * @param callable|null $callback
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function deleteExchange($name, array $options = [], callable $callback = null)
    {
        $ifUnused = array_key_exists('ifUnused', $options) ? (bool)$options['ifUnused'] : null;
        $noWait = array_key_exists('noWait', $options) ? (bool)$options['noWait'] : null;

        $outputFrame = Exchange\ExchangeDeleteFrame::create($name, $ifUnused, $noWait);
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);

        if (is_callable($callback)) {
            $this->on(self::EVENT_ON_CHANNEL_DELETE_EXCHANGE_CALLBACK, $callback);
        }
    }

    /**
     * Bind exchange
     *
     * @param string $name a source exchange name
     * @param string $exchangeName a destination exchange name
     * @param string $routingKey a routing key
     * @param array $options
     * @param callable|null $callback
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Exception\AMQPChannelException
     */
    public function bindExchange($name, $exchangeName, $routingKey, array $options = [], callable $callback = null)
    {
        if (!$this->connection->getFeatures()->exchangeToExchangeBindings) {
            throw new AMQPChannelException('Broker does not support exchange to exchange bindings');
        }

        if ($exchangeName === $name) {
            throw new AMQPChannelException('Exchange cannot bind to itself');
        }

        $noWait = array_key_exists('noWait', $options) ? $options['noWait'] : false;

        $outputFrame = Exchange\ExchangeBindFrame::create(
            $name,
            $exchangeName,
            $routingKey,
            $noWait,
            $options
        );
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);

        if (is_callable($callback)) {
            $this->on(self::EVENT_ON_CHANNEL_BIND_EXCHANGE_CALLBACK, $callback);
        }
    }

    /**
     * Unbind exchange
     *
     * @param string $name a source exchange name
     * @param string $exchangeName a destination exchange name
     * @param string $routingKey a routing key
     * @param array $options
     * @param callable|null $callback
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Exception\AMQPChannelException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function unbindExchange($name, $exchangeName, $routingKey, array $options = [], callable $callback = null)
    {
        if (!$this->connection->getFeatures()->exchangeToExchangeBindings) {
            throw new AMQPChannelException('Broker does not support exchange to exchange bindings');
        }

        if ($exchangeName === $name) {
            throw new AMQPChannelException('Exchange cannot unbind itself');
        }

        $noWait = array_key_exists('noWait', $options) ? $options['noWait'] : false;

        $outputFrame = Exchange\ExchangeUnbindFrame::create(
            $name,
            $exchangeName,
            $routingKey,
            $noWait,
            $options
        );
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);

        if (is_callable($callback)) {
            $this->on(self::EVENT_ON_CHANNEL_UNBIND_EXCHANGE_CALLBACK, $callback);
        }
    }

    /**
     * Publish message to exchange
     *
     * @param string $content The message content
     * @param string $exchangeName exchange name
     * @param string $routingKey routing key
     * @param array $options
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function publish($content, $exchangeName, $routingKey, array $options = [])
    {
        /**
         * Нам нужно собрать докучи три фрейма
         * 1. BasicPublishFrame сообщает брокеру , что будет чтото передавать.
         * 2. BasicHeaderFrame сообщает брокеру заголовки отправляемого сообщения
         * 3. BodyFrame содержит контент сообщения . Отправляется этот фрейм пачками по $this->channel->getConnection()->getMaximumFrameSize()
         */
        $outputBasicPublishFrame = Basic\BasicPublishFrame::create(
            $exchangeName, $routingKey
        );
        $outputBasicPublishFrame->frameChannelId = $this->id;
        $this->connection->command($outputBasicPublishFrame);

        $outputBasicHeaderFrame = new Basic\BasicHeaderFrame();
        $outputBasicHeaderFrame->frameChannelId = $this->id;
        $outputBasicHeaderFrame->contentLength = array_key_exists('contentLength', $options) ? $options['contentLength'] : null;
        $outputBasicHeaderFrame->contentType = array_key_exists('contentType', $options) ? $options['contentType'] : null;
        $outputBasicHeaderFrame->contentEncoding = array_key_exists('contentEncoding', $options) ? $options['contentEncoding'] : null;
        $outputBasicHeaderFrame->headers = array_key_exists('headers', $options) ? $options['headers'] : null;
        $outputBasicHeaderFrame->messageId = array_key_exists('messageId', $options) ? $options['messageId'] : null;
        $outputBasicHeaderFrame->deliveryMode = array_key_exists('deliveryMode', $options) ? $options['deliveryMode'] : null;
        $outputBasicHeaderFrame->correlationId = array_key_exists('correlationId', $options) ? $options['correlationId'] : null;
        $outputBasicHeaderFrame->replyTo = array_key_exists('replyTo', $options) ? $options['replyTo'] : null;
        $outputBasicHeaderFrame->expiration = array_key_exists('expiration', $options) ? $options['expiration'] : null;
        $outputBasicHeaderFrame->timestamp = array_key_exists('timestamp', $options) ? $options['timestamp'] : null;
        $outputBasicHeaderFrame->type = array_key_exists('type', $options) ? $options['type'] : null;
        $outputBasicHeaderFrame->userId = array_key_exists('userId', $options) ? $options['userId'] : null;
        $outputBasicHeaderFrame->appId = array_key_exists('appId', $options) ? $options['appId'] : null;
        $outputBasicHeaderFrame->clusterId = array_key_exists('clusterId', $options) ? $options['clusterId'] : null;

        $fInfo = new \finfo();
        if (null === $outputBasicHeaderFrame->contentType) {
            $outputBasicHeaderFrame->contentType = $fInfo->buffer($content, FILEINFO_MIME_TYPE);
        }
        if (null === $outputBasicHeaderFrame->contentEncoding) {
            $outputBasicHeaderFrame->contentEncoding = $fInfo->buffer($content, FILEINFO_MIME_ENCODING);
        }
        unset($fInfo);

        if (null === $outputBasicHeaderFrame->contentLength) {
            $outputBasicHeaderFrame->contentLength = strlen($content);
        }
        $this->connection->command($outputBasicHeaderFrame);

        $maxFrameSize = $this->connection->getMaximumFrameSize();
        $length = $outputBasicHeaderFrame->contentLength;

        $contentBuffer = $content;
        while ($length) {
            $outputBodyFrame = new BodyFrame();
            $outputBodyFrame->frameChannelId = $this->id;

            if ($length <= $maxFrameSize) {
                $outputBodyFrame->content = $contentBuffer;
                $contentBuffer = '';
                $length = 0;
            } else {
                $outputBodyFrame->content = substr($contentBuffer, 0, $maxFrameSize);
                $contentBuffer = substr($contentBuffer, $maxFrameSize);
                $length -= $maxFrameSize;
            }
            $this->connection->command($outputBodyFrame);
        }
    }

    /**
     * Send message directly to queue
     *
     * @param string $content a message content
     * @param string $name a queue name
     * @param array $options
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function sendToQueue($content, $name, array $options = [])
    {
        $this->publish($content, '', $name, $options);
    }

    /**
     * Bind a consumer to consume on message receive
     *
     * @param string $queueName a queue name
     * @param array $options
     * @param callable $callback
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function consume($queueName, array $options = [], callable $callback)
    {
        $consumerTag = array_key_exists('consumerTag', $options) ? $options['consumerTag'] : null;
        $noLocal = array_key_exists('noLocal', $options) ? (bool)$options['noLocal'] : null;
        $noAck = array_key_exists('noAck', $options) ? (bool)$options['noAck'] : null;
        $exclusive = array_key_exists('exclusive', $options) ? (bool)$options['exclusive'] : null;
        $noWait = array_key_exists('noWait', $options) ? (bool)$options['noWait'] : null;
        $arguments = array_key_exists('arguments', $options) ? $options['arguments'] : null;

        $outputFrame = Basic\BasicConsumeFrame::create(
            $queueName,
            $consumerTag,
            $noLocal,
            $noAck,
            $exclusive,
            $noWait,
            $arguments
        );
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);

        if (is_callable($callback)) {
            $this->on(self::EVENT_ON_CHANNEL_CONSUMEOK_CALLBACK, function (Basic\BasicConsumeOkFrame $incomingFrame) use ($callback) {
                $this->consumers[$incomingFrame->consumerTag] = $callback;
            });
        }
    }

    /**
     * Unbind consumer
     *
     * @param string $consumerTag
     * @param array $options
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function cancel($consumerTag, array $options = [])
    {
        $noWait = array_key_exists('noWait', $options) ? $options['noWait'] : null;

        $outputFrame = Basic\BasicCancelFrame::create($consumerTag, $noWait);
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);
    }

    /**
     * get message from queue
     *
     * @param string $queueName a queue name
     * @param array $options
     * @param callable|null $callback
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function get($queueName, array $options = [], callable $callback = null)
    {
        $noAck = array_key_exists('noAck', $options) ? $options['noAck'] : null;

        $outputFrame = Basic\BasicGetFrame::create(
            $queueName,
            $noAck
        );
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);

        if (is_callable($callback)) {
            $this->on(self::EVENT_DISPATCH_MESSAGE, $callback);
        }
    }

    /**
     * Ack message by delivery tag
     *
     * @param int $deliveryTag
     * @param array $options
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function ack($deliveryTag, array $options = [])
    {
        $multiple = array_key_exists('multiple', $options) ? (int)$options['multiple'] : null;

        $outputFrame = Basic\BasicAckFrame::create($deliveryTag, $multiple);
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);
    }

    /**
     * Nack message
     *
     * @param $deliveryTag
     * @param array $options
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function nack($deliveryTag, array $options = [])
    {
        $multiple = array_key_exists('multiple', $options) ? (int)$options['multiple'] : null;
        $requeue = array_key_exists('requeue', $options) ? (bool)$options['requeue'] : null;

        $outputFrame = Basic\BasicNackFrame::create($deliveryTag, $multiple, $requeue);
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);
    }

    /**
     * Reject a message
     *
     * @param $deliveryTag
     * @param array $options
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function reject($deliveryTag, array $options = [])
    {
        $requeue = array_key_exists('requeue', $options) ? $options['requeue'] : null;

        $outputFrame = Basic\BasicRejectFrame::create($deliveryTag, $requeue);
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);
    }

    /**
     * Redeliver unacknowledged messages.
     *
     * @param bool $requeue
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    public function recover($requeue = true)
    {
        $outputFrame = Basic\BasicRecoverFrame::create($requeue);
        $outputFrame->frameChannelId = $this->id;
        $this->connection->command($outputFrame);
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return Connection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * @return bool
     */
    public function isConnected()
    {
        return $this->isConnected;
    }

    /**
     * @return $this
     */
    private function triggerOneAndUnbind()
    {
        $args = func_get_args();
        $name = array_shift($args);
        if ($this->addThisToEvents) {
            array_unshift($args, $this);
        }
        if (isset($this->eventHandlers[$name])) {
            $cb = array_shift($this->eventHandlers[$name]);
            if ($cb(...$args) === true) {
                return $this;
            }
        }
        return $this;
    }
}
