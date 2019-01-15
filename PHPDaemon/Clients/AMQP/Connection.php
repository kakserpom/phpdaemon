<?php

namespace PHPDaemon\Clients\AMQP;

use PHPDaemon\Clients\AMQP\Driver\ConnectionOptions;
use PHPDaemon\Clients\AMQP\Driver\Exception\AMQPConnectionException;
use PHPDaemon\Clients\AMQP\Driver\Features;
use PHPDaemon\Clients\AMQP\Driver\PackageInfo;
use PHPDaemon\Clients\AMQP\Driver\Protocol\CommandInterface;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Parser\Frame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Parser\Frame as FrameParser;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Parser\Table as TableParser;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection\ConnectionCloseFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection\ConnectionOpenFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection\ConnectionOpenOkFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection\ConnectionStartFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection\ConnectionStartOkFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection\ConnectionTuneFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection\ConnectionTuneOkFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\HeartbeatFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Serializer\Frame as FrameSerializer;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Serializer\Table as TableSerializer;
use PHPDaemon\Network\ClientConnection;
use PHPDaemon\Utils\Binary;

/**
 * Class Connection
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP
 */
class Connection extends ClientConnection implements CommandInterface
{

    /**
     * The AMQP protocol header is sent by the client before any frame-based
     * communication takes place.  It is the only data transferred that is not
     * a frame.
     */
    const PROTOCOL_HEADER = "AMQP\x00\x00\x09\x01";

    /**
     * The maximum number of channels.
     *
     * AMQP channel ID is 2 bytes, but zero is reserved for connection-level
     * communication.
     */
    const MAXIMUM_CHANNELS = 0xffff - 1;

    /**
     * The maximum frame size the client supports.
     *
     * Note: RabbitMQ's default is 0x20000 (128 KB), our limit is higher to
     * allow for some server-side configurability.
     */
    const MAXIMUM_FRAME_SIZE = 0x80000; // 512 KB

    /**
     * The broker sends channelMax of zero in the tune frame if it does not
     * impose a channel limit.
     */
    const UNLIMITED_CHANNELS = 0;

    /**
     * The broker sends frameMax of zero in the tune frame if it does not impose
     * a frame size limit.
     */
    const UNLIMITED_FRAME_SIZE = 0;

    /**
     * The broker sends a heartbeat of zero in the tune frame if it does not use
     * heartbeats.
     */
    const HEARTBEAT_DISABLED = 0;

    /**
     * Event raised when protocol handshake ready
     */
    const EVENT_ON_HANDSHAKE = 'event.amqp.connection.handshake';

    /**
     * Event raised when connection close frame incoming
     */

    const EVENT_ON_CONNECTION_CLOSE = 'event.amqp.connection.close';

    /**
     * @var FrameParser
     */
    protected $parser;

    /**
     * @var FrameSerializer
     */
    protected $serializer;

    /**
     * @var ConnectionOptions
     */
    protected $connectionOptions;

    /**
     * @var bool
     */
    protected $isHandshaked = false;

    /**
     * The broker's supported features.
     * @var Features
     */
    private $features;

    /**
     * @var int
     */
    private $maximumChannelCount;

    /**
     * @var int
     */
    private $maximumFrameSize;

    /**
     * @var array
     */
    private $channels = [];

    /**
     * @var int
     */
    private $nextChannelId = 1;

    /**
     * @var bool
     */
    private $debug = false;

    /**
     *
     */
    protected function init()
    {
        $this->parser = new FrameParser(new TableParser());
        $this->serializer = new FrameSerializer(new TableSerializer());

        $this->connectionOptions = new ConnectionOptions(
            $this->pool->config->host->value,
            $this->pool->config->port->value,
            $this->pool->config->username->value,
            $this->pool->config->password->value,
            $this->pool->config->vhost->value
        );

        $this->debug = isset($this->pool->config->debug);
    }

    /**
     *
     */
    public function onReady()
    {
        if ($this->isHandshaked) {
            parent::onReady();
        }

        $this->write(self::PROTOCOL_HEADER);

        parent::onReady();
    }

    /**
     *
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Exception\AMQPConnectionException
     * @throws \InvalidArgumentException
     */
    protected function onRead()
    {
        if ($this->getInputLength() <= 0) {
            return;
        }
        // set busy for connection
        $this->busy = true;

        /**
         * 1) read Frame::HEADER_SIZE
         * 2) get payload length from header
         * 3) concatenate header and payload + 1 byte (for Constants::FRAME_END) into buffer
         * 4) parse buffer
         * 5) do stuff...
         * 6) if bev contains more data go to 1
         */
        frame:
        $header = $this->readExact(Frame::HEADER_SIZE);
        if ($header === false) {
            return;
        }
        $framePayloadSize = Binary::b2i(substr($header, Frame::HEADER_TYPE_SIZE + Frame::HEADER_CHANNEL_SIZE, Frame::HEADER_PAYLOAD_LENGTH_SIZE));

        $payload = $this->readExact($framePayloadSize + 1);
        if ($payload === false) {
            return;
        }
        $buffer = $header . $payload;

        $frame = $this->parser->feed($buffer);
        if ($frame === null) {
            return;
        }
        if ($this->debug) {
            $this->log(sprintf('[AMQP] %s packet received', get_class($frame)));
        }

        if (!$this->isHandshaked) {
            switch (true) {
                case $frame instanceof ConnectionStartFrame:
                    if ($frame->versionMajor !== 0 || $frame->versionMinor !== 9) {
                        throw AMQPConnectionException::handshakeFailed(
                            $this->connectionOptions,
                            sprintf(
                                'the broker reported an unexpected AMQP version (v%d.%d)',
                                $frame->versionMajor,
                                $frame->versionMinor
                            )
                        );
                    }
                    if (!preg_match('/\bAMQPLAIN\b/', $frame->mechanisms)) {
                        throw AMQPConnectionException::handshakeFailed(
                            $this->connectionOptions,
                            'the AMQPLAIN authentication mechanism is not supported'
                        );
                    }

                    $this->features = new Features();
                    $properties = $frame->serverProperties;
                    if (isset($properties['product']) && 'RabbitMQ' === $properties['product']) {
                        $this->features->qosSizeLimits = false;
                    }
                    if (array_key_exists('capabilities', $properties)) {
                        if (array_key_exists('per_consumer_qos', $properties['capabilities'])) {
                            $this->features->perConsumerQos = (bool)$properties['capabilities']['per_consumer_qos'];
                        }
                        if (array_key_exists('exchange_exchange_bindings', $properties['capabilities'])) {
                            $this->features->exchangeToExchangeBindings = (bool)$properties['capabilities']['exchange_exchange_bindings'];
                        }
                    }

                    // Serialize credentials in "AMQPLAIN" format, which is essentially an
                    // AMQP table without the 4-byte size header ...
                    $user = $this->connectionOptions->getUsername();
                    $pass = $this->connectionOptions->getPassword();

                    $credentials = "\x05LOGINS" . pack('N', strlen($user)) . $user
                        . "\x08PASSWORDS" . pack('N', strlen($pass)) . $pass;

                    $this->command(ConnectionStartOkFrame::create(
                        [
                            'product' => $this->connectionOptions->getProductName(),
                            'version' => $this->connectionOptions->getProductVersion(),
                            'platform' => PackageInfo::AMQP_PLATFORM,
                            'copyright' => PackageInfo::AMQP_COPYRIGHT,
                            'information' => PackageInfo::AMQP_INFORMATION,

                        ],
                        'AMQPLAIN',
                        $credentials
                    ));
                    break;
                case $frame instanceof ConnectionTuneFrame:
                    $this->maximumChannelCount = self::MAXIMUM_CHANNELS;
                    if ($frame->channelMax === self::UNLIMITED_CHANNELS) {
                        $this->maximumChannelCount = self::MAXIMUM_CHANNELS;
                    } elseif ($frame->channelMax < self::MAXIMUM_CHANNELS) {
                        $this->maximumChannelCount = $frame->channelMax;
                    }

                    $this->maximumFrameSize = self::MAXIMUM_FRAME_SIZE;
                    if ($frame->frameMax === self::UNLIMITED_FRAME_SIZE) {
                        $this->maximumFrameSize = self::MAXIMUM_FRAME_SIZE;
                    } elseif ($frame->frameMax < self::MAXIMUM_FRAME_SIZE) {
                        $this->maximumFrameSize = $frame->frameMax;
                    }

                    $heartbeatInterval = 0;
                    if (!self::HEARTBEAT_DISABLED) {
                        $heartbeatInterval = $this->connectionOptions->getHeartbeatInterval();
                        if (null === $heartbeatInterval) {
                            $heartbeatInterval = $frame->heartbeat;
                        } elseif ($frame->heartbeat < $heartbeatInterval) {
                            $heartbeatInterval = $frame->heartbeat;
                        }
                    }

                    $outputFrame = ConnectionTuneOkFrame::create(
                        $this->maximumChannelCount,
                        $this->maximumFrameSize,
                        $heartbeatInterval
                    );

                    if ($outputFrame->heartbeat > 0) {
                        /**
                         * We need to set timeout value = ConnectionTuneFrame::heartbeat + 5 sec
                         */
                        $timeout = $outputFrame->heartbeat + 5;
                        $this->setTimeout($timeout);
                        $this->connectionOptions->setHeartbeatInterval($outputFrame->heartbeat);
                        $this->connectionOptions->setConnectionTimeout($timeout);
                    }

                    $this->command($outputFrame);

                    $outputFrame = ConnectionOpenFrame::create(
                        $this->connectionOptions->getVhost()
                    );
                    $this->command($outputFrame);
                    break;

                case $frame instanceof ConnectionOpenOkFrame:
                    $this->isHandshaked = true;
                    $this->openChannel(function ($channel) {
                        $this->trigger(self::EVENT_ON_HANDSHAKE, $channel);
                    });
                    break;
            }

            $this->checkFree();
            return;
        }

        switch (true) {
            case $frame instanceof HeartbeatFrame:
                $this->command($frame);
                break;
            case $frame instanceof ConnectionCloseFrame:
                $this->trigger(self::EVENT_ON_CONNECTION_CLOSE, $frame->replyCode, $frame->replyText);
                $this->close();
                return;
                break;
            default:
                if (isset($frame->frameChannelId)
                    && $frame->frameChannelId > 0
                    && array_key_exists($frame->frameChannelId, $this->channels)) {
                    /** @var Channel $channel */
                    $channel = $this->channels[$frame->frameChannelId];
                    $channel->trigger(get_class($frame), $frame);
                    break; // exit
                }

                $this->trigger(get_class($frame), $frame);
                break;
        }

        if ($this->bev && $this->getInputLength() > 0) {
            goto frame;
        }

        $this->checkFree();
    }

    /**
     * @param int $id
     * @param callable $callback
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Exception\AMQPConnectionException
     */
    public function getChannel(callable $callback, $id = 1)
    {
        if (count($this->channels) === 0) {
            $this->openChannel($callback);
            return;
        }

        if (is_callable($callback)) {
            $callback($this->channels[$id]);
        }
    }

    /**
     * @param callable|null $callback
     * @throws \InvalidArgumentException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     * @throws \PHPDaemon\Clients\AMQP\Driver\Exception\AMQPConnectionException
     */
    public function openChannel(callable $callback = null)
    {
        new Channel($this, $callback);
    }

    /**
     * @return int
     * @throws \PHPDaemon\Clients\AMQP\Driver\Exception\AMQPConnectionException
     */
    public function findChannelId()
    {
        // first check in range [next, max] ...
        for (
            $channelId = $this->nextChannelId;
            $channelId <= $this->maximumChannelCount;
            ++$channelId
        ) {
            if (!isset($this->channels[$channelId])) {
                $this->nextChannelId = $channelId + 1;

                return $channelId;
            }
        }

        // then check in range [min, next) ...
        for (
            $channelId = 1;
            $channelId < $this->nextChannelId;
            ++$channelId
        ) {
            if (!isset($this->channels[$channelId])) {
                $this->nextChannelId = $channelId + 1;

                return $channelId;
            }
        }

        throw new AMQPConnectionException('No available channels');
    }

    /**
     * @param OutgoingFrame $frame
     * @param callable|null $callback
     * @return bool
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     * @throws \InvalidArgumentException
     */
    public function command(OutgoingFrame $frame, callable $callback = null)
    {
        if ($callback) {
            $this->onResponse($callback);
        }
        $serializedFrame = $this->serializer->serialize($frame);
        return $this->write($serializedFrame);
    }


    public function addChannel($id, Channel $channel)
    {
        $this->channels[$id] = $channel;
        $this->nextChannelId = max(array_keys($this->channels)) + 1;
        return $this;
    }

    /**
     * @return Features
     */
    public function getFeatures()
    {
        return $this->features;
    }

    /**
     * @return ConnectionOptions
     */
    public function getConnectionOptions()
    {
        return $this->connectionOptions;
    }

    /**
     * @return int
     */
    public function getMaximumChannelCount()
    {
        return $this->maximumChannelCount;
    }

    /**
     * @return int
     */
    public function getMaximumFrameSize()
    {
        return $this->maximumFrameSize;
    }

    /**
     * @return bool
     */
    public function isHandshaked()
    {
        return $this->isHandshaked;
    }
}
