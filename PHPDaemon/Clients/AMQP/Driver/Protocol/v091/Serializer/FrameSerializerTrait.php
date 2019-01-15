<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Serializer;

use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Access;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\BodyFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Channel;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Confirm;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\HeartbeatFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Tx;

/**
 * Class FrameSerializerTrait
 *
 * @property TableInterface tableSerializer
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Serializer
 */
trait FrameSerializerTrait
{
    use ScalarSerializerTrait;

    /**
     * @param OutgoingFrame $frame
     * @return string
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     * @throws \InvalidArgumentException
     */
    public function serialize(OutgoingFrame $frame)
    {

        switch (true) {
            case $frame instanceof HeartbeatFrame:
                return "\x08\x00\x00\x00\x00\x00\x00\xce";

            case $frame instanceof BodyFrame:
                return "\x03" . \pack('nN', $frame->frameChannelId, \strlen($frame->content)) . $frame->content . "\xce";

            case $frame instanceof Connection\ConnectionStartOkFrame:
                $payload = "\x00\x0a\x00\x0b"
                    . $this->tableSerializer->serialize($frame->clientProperties)
                    . $this->serializeShortString($frame->mechanism)
                    . $this->serializeLongString($frame->response)
                    . $this->serializeShortString($frame->locale);

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Connection\ConnectionSecureOkFrame:
                $payload = "\x00\x0a\x00\x15"
                    . $this->serializeLongString($frame->response);
                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Connection\ConnectionTuneOkFrame:
                $payload = "\x00\x0a\x00\x1f"
                    . \pack('nNn', $frame->channelMax, $frame->frameMax, $frame->heartbeat);
                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Connection\ConnectionOpenFrame:
                $payload = "\x00\x0a\x00\x28"
                    . $this->serializeShortString($frame->virtualHost)
                    . $this->serializeShortString($frame->capabilities)
                    . ($frame->insist ? "\x01" : "\x00");

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Connection\ConnectionCloseFrame:
                $payload = "\x00\x0a\x00\x32"
                    . \pack('n', $frame->replyCode)
                    . $this->serializeShortString($frame->replyText)
                    . \pack('nn', $frame->classId, $frame->methodId);

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Connection\ConnectionCloseOkFrame:
                return "\x01" . \pack('n', $frame->frameChannelId) . "\x00\x00\x00\x04\x00\x0a\x00\x33\xce";

            case $frame instanceof Channel\ChannelOpenFrame:
                $payload = "\x00\x14\x00\x0a"
                    . $this->serializeShortString($frame->outOfBand);

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Channel\ChannelFlowFrame:
                $payload = "\x00\x14\x00\x14"
                    . ($frame->active ? "\x01" : "\x00");

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Channel\ChannelFlowOkFrame:
                $payload = "\x00\x14\x00\x15"
                    . ($frame->active ? "\x01" : "\x00");

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Channel\ChannelCloseFrame:
                $payload = "\x00\x14\x00\x28"
                    . \pack('n', $frame->replyCode)
                    . $this->serializeShortString($frame->replyText)
                    . \pack('nn', $frame->classId, $frame->methodId);

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Channel\ChannelCloseOkFrame:
                return "\x01" . \pack('n', $frame->frameChannelId) . "\x00\x00\x00\x04\x00\x14\x00\x29\xce";

            case $frame instanceof Access\AccessRequestFrame:
                $payload = "\x00\x1e\x00\x0a"
                    . $this->serializeShortString($frame->realm)
                    . \chr(
                        $frame->exclusive
                        | $frame->passive << 1
                        | $frame->active << 2
                        | $frame->write << 3
                        | $frame->read << 4
                    );

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Exchange\ExchangeDeclareFrame:
                $payload = "\x00\x28\x00\x0a"
                    . \pack('n', $frame->reserved1)
                    . $this->serializeShortString($frame->exchange)
                    . $this->serializeShortString($frame->type)
                    . \chr(
                        $frame->passive
                        | $frame->durable << 1
                        | $frame->autoDelete << 2
                        | $frame->internal << 3
                        | $frame->nowait << 4
                    )
                    . $this->tableSerializer->serialize($frame->arguments);

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Exchange\ExchangeDeleteFrame:
                $payload = "\x00\x28\x00\x14"
                    . \pack('n', $frame->reserved1)
                    . $this->serializeShortString($frame->exchange)
                    . \chr(
                        $frame->ifUnused
                        | $frame->nowait << 1
                    );

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Exchange\ExchangeBindFrame:
                $payload = "\x00\x28\x00\x1e"
                    . \pack('n', $frame->reserved1)
                    . $this->serializeShortString($frame->destination)
                    . $this->serializeShortString($frame->source)
                    . $this->serializeShortString($frame->routingKey)
                    . ($frame->nowait ? "\x01" : "\x00")
                    . $this->tableSerializer->serialize($frame->arguments);

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Exchange\ExchangeUnbindFrame:
                $payload = "\x00\x28\x00\x28"
                    . \pack('n', $frame->reserved1)
                    . $this->serializeShortString($frame->destination)
                    . $this->serializeShortString($frame->source)
                    . $this->serializeShortString($frame->routingKey)
                    . ($frame->nowait ? "\x01" : "\x00")
                    . $this->tableSerializer->serialize($frame->arguments);

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Queue\QueueDeclareFrame:
                $payload = "\x00\x32\x00\x0a"
                    . \pack('n', $frame->reserved1)
                    . $this->serializeShortString($frame->queue)
                    . \chr(
                        $frame->passive
                        | $frame->durable << 1
                        | $frame->exclusive << 2
                        | $frame->autoDelete << 3
                        | $frame->nowait << 4
                    )
                    . $this->tableSerializer->serialize($frame->arguments);

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Queue\QueueBindFrame:
                $payload = "\x00\x32\x00\x14"
                    . \pack('n', $frame->reserved1)
                    . $this->serializeShortString($frame->queue)
                    . $this->serializeShortString($frame->exchange)
                    . $this->serializeShortString($frame->routingKey)
                    . ($frame->nowait ? "\x01" : "\x00")
                    . $this->tableSerializer->serialize($frame->arguments);

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Queue\QueuePurgeFrame:
                $payload = "\x00\x32\x00\x1e"
                    . \pack('n', $frame->reserved1)
                    . $this->serializeShortString($frame->queue)
                    . ($frame->nowait ? "\x01" : "\x00");

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Queue\QueueDeleteFrame:
                $payload = "\x00\x32\x00\x28"
                    . \pack('n', $frame->reserved1)
                    . $this->serializeShortString($frame->queue)
                    . \chr(
                        $frame->ifUnused
                        | $frame->ifEmpty << 1
                        | $frame->nowait << 2
                    );

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Queue\QueueUnbindFrame:
                $payload = "\x00\x32\x00\x32"
                    . \pack('n', $frame->reserved1)
                    . $this->serializeShortString($frame->queue)
                    . $this->serializeShortString($frame->exchange)
                    . $this->serializeShortString($frame->routingKey)
                    . $this->tableSerializer->serialize($frame->arguments);

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Basic\BasicHeaderFrame:
                $flags = 0;
                $properties = '';

                if (null !== $frame->contentType) {
                    $flags |= 32768;
                    $properties .= $this->serializeShortString($frame->contentType);
                }

                if (null !== $frame->contentEncoding) {
                    $flags |= 16384;
                    $properties .= $this->serializeShortString($frame->contentEncoding);
                }

                if (null !== $frame->headers) {
                    $flags |= 8192;
                    $properties .= $this->tableSerializer->serialize($frame->headers);
                }

                if (null !== $frame->deliveryMode) {
                    $flags |= 4096;
                    $properties .= \pack('c', $frame->deliveryMode);
                }

                if (null !== $frame->priority) {
                    $flags |= 2048;
                    $properties .= \pack('c', $frame->priority);
                }

                if (null !== $frame->correlationId) {
                    $flags |= 1024;
                    $properties .= $this->serializeShortString($frame->correlationId);
                }

                if (null !== $frame->replyTo) {
                    $flags |= 512;
                    $properties .= $this->serializeShortString($frame->replyTo);
                }

                if (null !== $frame->expiration) {
                    $flags |= 256;
                    $properties .= $this->serializeShortString($frame->expiration);
                }

                if (null !== $frame->messageId) {
                    $flags |= 128;
                    $properties .= $this->serializeShortString($frame->messageId);
                }

                if (null !== $frame->timestamp) {
                    $flags |= 64;
                    $properties .= \pack('J', $frame->timestamp);
                }

                if (null !== $frame->type) {
                    $flags |= 32;
                    $properties .= $this->serializeShortString($frame->type);
                }

                if (null !== $frame->userId) {
                    $flags |= 16;
                    $properties .= $this->serializeShortString($frame->userId);
                }

                if (null !== $frame->appId) {
                    $flags |= 8;
                    $properties .= $this->serializeShortString($frame->appId);
                }

                if (null !== $frame->clusterId) {
                    $flags |= 4;
                    $properties .= $this->serializeShortString($frame->clusterId);
                }

                $payload = "\x00\x3c\x00\x00"
                    . \pack('Jn', $frame->contentLength, $flags)
                    . $properties;

                return "\x02" . \pack('nN', $frame->frameChannelId, \strlen($payload)) . $payload . "\xce";

            case $frame instanceof Basic\BasicQosFrame:
                $payload = "\x00\x3c\x00\x0a"
                    . \pack('Nn', $frame->prefetchSize, $frame->prefetchCount)
                    . ($frame->global ? "\x01" : "\x00");

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Basic\BasicConsumeFrame:
                $payload = "\x00\x3c\x00\x14"
                    . \pack('n', $frame->reserved1)
                    . $this->serializeShortString($frame->queue)
                    . $this->serializeShortString($frame->consumerTag)
                    . \chr(
                        $frame->noLocal
                        | $frame->noAck << 1
                        | $frame->exclusive << 2
                        | $frame->nowait << 3
                    )
                    . $this->tableSerializer->serialize($frame->arguments);

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Basic\BasicCancelFrame:
                $payload = "\x00\x3c\x00\x1e"
                    . $this->serializeShortString($frame->consumerTag)
                    . ($frame->nowait ? "\x01" : "\x00");

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Basic\BasicPublishFrame:
                $payload = "\x00\x3c\x00\x28"
                    . \pack('n', $frame->reserved1)
                    . $this->serializeShortString($frame->exchange)
                    . $this->serializeShortString($frame->routingKey)
                    . \chr(
                        $frame->mandatory
                        | $frame->immediate << 1
                    );

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Basic\BasicGetFrame:
                $payload = "\x00\x3c\x00\x46"
                    . \pack('n', $frame->reserved1)
                    . $this->serializeShortString($frame->queue)
                    . ($frame->noAck ? "\x01" : "\x00");

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Basic\BasicAckFrame:
                $payload = "\x00\x3c\x00\x50"
                    . \pack('J', $frame->deliveryTag)
                    . ($frame->multiple ? "\x01" : "\x00");

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Basic\BasicRejectFrame:
                $payload = "\x00\x3c\x00\x5a"
                    . \pack('J', $frame->deliveryTag)
                    . ($frame->requeue ? "\x01" : "\x00");

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Basic\BasicRecoverFrame:
                $payload = "\x00\x3c\x00\x6e"
                    . ($frame->requeue ? "\x01" : "\x00");

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Basic\BasicNackFrame:
                $payload = "\x00\x3c\x00\x78"
                    . \pack('J', $frame->deliveryTag)
                    . \chr(
                        $frame->multiple
                        | $frame->requeue << 1
                    );

                return $this->packX01Payload($frame, $payload);

            case $frame instanceof Tx\TxSelectFrame:
                return "\x01" . \pack('n', $frame->frameChannelId) . "\x00\x00\x00\x04\x00\x5a\x00\x0a\xce";

            case $frame instanceof Tx\TxCommitFrame:
                return "\x01" . \pack('n', $frame->frameChannelId) . "\x00\x00\x00\x04\x00\x5a\x00\x14\xce";

            case $frame instanceof Tx\TxRollbackFrame:
                return "\x01" . \pack('n', $frame->frameChannelId) . "\x00\x00\x00\x04\x00\x5a\x00\x1e\xce";

            case $frame instanceof Confirm\ConfirmSelectFrame:
                $payload = "\x00\x55\x00\x0a"
                    . ($frame->nowait ? "\x01" : "\x00");

                return $this->packX01Payload($frame, $payload);

        }

        throw new AMQPProtocolException(
            sprintf('Frame %s not implemented yet', get_class($frame)));
    }

    /**
     * @param $frame
     * @param string $payload
     * @return string
     */
    public function packX01Payload($frame, $payload)
    {
        return "\x01" . \pack('nN', $frame->frameChannelId, \strlen($payload)) . $payload . "\xce";
    }
}
