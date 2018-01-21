<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Parser;

use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Access;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Channel;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Confirm;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Exchange;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\IncomingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Queue;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Tx;

/**
 * Class MethodFrameParserTrait
 *
 * @property TableInterface tableParser
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Parser
 */
trait MethodFrameParserTrait
{
    /**
     * @return FrameInterface|OutgoingFrame|IncomingFrame
     * @throws AMQPProtocolException
     */
    private function parseMethodFrame()
    {
        list(, $class, $method) = \unpack('n2', $this->buffer);
        $this->buffer = \substr($this->buffer, 4);

        // class "connection"
        if ($class === 10) {
            // method "connection.start"
            if ($method === 10) {
                $frame = new Connection\ConnectionStartFrame();

                // consume (a) "version-major" (octet)
                // consume (b) "version-minor" (octet)
                $fields = \unpack('ca/cb', $this->buffer);
                $this->buffer = \substr($this->buffer, 2);
                $frame->versionMajor = $fields['a'];
                $frame->versionMinor = $fields['b'];

                // consume "server-properties" (table)
                $frame->serverProperties = $this->tableParser->parse($this->buffer);

                // consume "mechanisms" (longstr)
                list(, $length) = \unpack('N', $this->buffer);
                $frame->mechanisms = \substr($this->buffer, 4, $length);
                $this->buffer = \substr($this->buffer, 4 + $length);

                // consume "locales" (longstr)
                list(, $length) = \unpack('N', $this->buffer);
                $frame->locales = \substr($this->buffer, 4, $length);
                $this->buffer = \substr($this->buffer, 4 + $length);

                return $frame;

                // method "connection.secure"
            }
            if ($method === 20) {
                $frame = new Connection\ConnectionSecureFrame();

                // consume "challenge" (longstr)
                list(, $length) = \unpack('N', $this->buffer);
                $frame->challenge = \substr($this->buffer, 4, $length);
                $this->buffer = \substr($this->buffer, 4 + $length);

                return $frame;

                // method "connection.tune"
            }
            if ($method === 30) {
                $frame = new Connection\ConnectionTuneFrame();

                // consume (a) "channel-max" (short)
                // consume (b) "frame-max" (long)
                // consume (c) "heartbeat" (short)
                $fields = \unpack('na/Nb/nc', $this->buffer);
                $this->buffer = \substr($this->buffer, 8);
                $frame->channelMax = $fields['a'];
                $frame->frameMax = $fields['b'];
                $frame->heartbeat = $fields['c'];

                return $frame;

                // method "connection.open-ok"
            }
            if ($method === 41) {
                $frame = new Connection\ConnectionOpenOkFrame();

                // consume "known-hosts" (shortstr)
                $length = \ord($this->buffer);
                $frame->knownHosts = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                return $frame;

                // method "connection.close"
            }
            if ($method === 50) {
                $frame = new Connection\ConnectionCloseFrame();

                // consume "replyCode" (short)
                list(, $frame->replyCode) = \unpack('n', $this->buffer);
                $this->buffer = \substr($this->buffer, 2);

                // consume "reply-text" (shortstr)
                $length = \ord($this->buffer);
                $frame->replyText = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                // consume (a) "class-id" (short)
                // consume (b) "method-id" (short)
                $fields = \unpack('na/nb', $this->buffer);
                $this->buffer = \substr($this->buffer, 4);
                $frame->classId = $fields['a'];
                $frame->methodId = $fields['b'];

                return $frame;

                // method "connection.close-ok"
            }
            if ($method === 51) {
                return new Connection\ConnectionCloseOkFrame();

                // method "connection.blocked"
            }
            if ($method === 60) {
                $frame = new Connection\ConnectionBlockedFrame();

                // consume "reason" (shortstr)
                $length = \ord($this->buffer);
                $frame->reason = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                return $frame;

                // method "connection.unblocked"
            }
            if ($method === 61) {
                return new Connection\ConnectionUnblockedFrame();
            }

            throw new AMQPProtocolException(
                'Frame method (' . $method . ') is invalid for class "connection".'
            );

            // class "channel"
        }
        if ($class === 20) {
            // method "channel.open-ok"
            if ($method === 11) {
                $frame = new Channel\ChannelOpenOkFrame();

                // consume "channel-id" (longstr)
                list(, $length) = \unpack('N', $this->buffer);
                $frame->channelId = \substr($this->buffer, 4, $length);
                $this->buffer = \substr($this->buffer, 4 + $length);

                return $frame;

                // method "channel.flow"
            }
            if ($method === 20) {
                $frame = new Channel\ChannelFlowFrame();

                // consume "active" (bit)
                $frame->active = \ord($this->buffer) !== 0;
                $this->buffer = \substr($this->buffer, 1);

                return $frame;

                // method "channel.flow-ok"
            }
            if ($method === 21) {
                $frame = new Channel\ChannelFlowOkFrame();

                // consume "active" (bit)
                $frame->active = \ord($this->buffer) !== 0;
                $this->buffer = \substr($this->buffer, 1);

                return $frame;

                // method "channel.close"
            }
            if ($method === 40) {
                $frame = new Channel\ChannelCloseFrame();

                // consume "replyCode" (short)
                list(, $frame->replyCode) = \unpack('n', $this->buffer);
                $this->buffer = \substr($this->buffer, 2);

                // consume "reply-text" (shortstr)
                $length = \ord($this->buffer);
                $frame->replyText = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                // consume (a) "class-id" (short)
                // consume (b) "method-id" (short)
                $fields = \unpack('na/nb', $this->buffer);
                $this->buffer = \substr($this->buffer, 4);
                $frame->classId = $fields['a'];
                $frame->methodId = $fields['b'];

                return $frame;

                // method "channel.close-ok"
            }
            if ($method === 41) {
                return new Channel\ChannelCloseOkFrame();
            }

            throw new AMQPProtocolException(
                'Frame method (' . $method . ') is invalid for class "channel".'
            );

            // class "access"
        }
        if ($class === 30) {
            // method "access.request-ok"
            if ($method === 11) {
                $frame = new Access\AccessRequestOkFrame();

                // consume "reserved1" (short)
                list(, $frame->reserved1) = \unpack('n', $this->buffer);
                $this->buffer = \substr($this->buffer, 2);

                return $frame;
            }

            throw new AMQPProtocolException(
                'Frame method (' . $method . ') is invalid for class "access".'
            );

            // class "exchange"
        }
        if ($class === 40) {
            // method "exchange.declare-ok"
            if ($method === 11) {
                return new Exchange\ExchangeDeclareOkFrame();

                // method "exchange.delete-ok"
            }
            if ($method === 21) {
                return new Exchange\ExchangeDeleteOkFrame();

                // method "exchange.bind-ok"
            }
            if ($method === 31) {
                return new Exchange\ExchangeBindOkFrame();

                // method "exchange.unbind-ok"
            }
            if ($method === 51) {
                return new Exchange\ExchangeUnbindOkFrame();
            }

            throw new AMQPProtocolException(
                'Frame method (' . $method . ') is invalid for class "exchange".'
            );

            // class "queue"
        }
        if ($class === 50) {
            // method "queue.declare-ok"
            if ($method === 11) {
                $frame = new Queue\QueueDeclareOkFrame();

                // consume "queue" (shortstr)
                $length = \ord($this->buffer);
                $frame->queue = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                // consume (a) "message-count" (long)
                // consume (b) "consumer-count" (long)
                $fields = \unpack('Na/Nb', $this->buffer);
                $this->buffer = \substr($this->buffer, 8);
                $frame->messageCount = $fields['a'];
                $frame->consumerCount = $fields['b'];

                return $frame;

                // method "queue.bind-ok"
            }
            if ($method === 21) {
                return new Queue\QueueBindOkFrame();

                // method "queue.purge-ok"
            }
            if ($method === 31) {
                $frame = new Queue\QueuePurgeOkFrame();

                // consume "messageCount" (long)
                list(, $frame->messageCount) = \unpack('N', $this->buffer);
                $this->buffer = \substr($this->buffer, 4);

                return $frame;

                // method "queue.delete-ok"
            }
            if ($method === 41) {
                $frame = new Queue\QueueDeleteOkFrame();

                // consume "messageCount" (long)
                list(, $frame->messageCount) = \unpack('N', $this->buffer);
                $this->buffer = \substr($this->buffer, 4);

                return $frame;

                // method "queue.unbind-ok"
            }
            if ($method === 51) {
                return new Queue\QueueUnbindOkFrame();
            }

            throw new AMQPProtocolException(
                'Frame method (' . $method . ') is invalid for class "queue".'
            );

            // class "basic"
        }
        if ($class === 60) {
            // method "basic.qos-ok"
            if ($method === 11) {
                return new Basic\BasicQosOkFrame();

                // method "basic.consume-ok"
            }
            if ($method === 21) {
                $frame = new Basic\BasicConsumeOkFrame();

                // consume "consumer-tag" (shortstr)
                $length = \ord($this->buffer);
                $frame->consumerTag = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                return $frame;

                // method "basic.cancel-ok"
            }
            if ($method === 31) {
                $frame = new Basic\BasicCancelOkFrame();

                // consume "consumer-tag" (shortstr)
                $length = \ord($this->buffer);
                $frame->consumerTag = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                return $frame;

                // method "basic.return"
            }
            if ($method === 50) {
                $frame = new Basic\BasicReturnFrame();

                // consume "replyCode" (short)
                list(, $frame->replyCode) = \unpack('n', $this->buffer);
                $this->buffer = \substr($this->buffer, 2);

                // consume "reply-text" (shortstr)
                $length = \ord($this->buffer);
                $frame->replyText = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                // consume "exchange" (shortstr)
                $length = \ord($this->buffer);
                $frame->exchange = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                // consume "routing-key" (shortstr)
                $length = \ord($this->buffer);
                $frame->routingKey = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                return $frame;

                // method "basic.deliver"
            }
            if ($method === 60) {
                $frame = new Basic\BasicDeliverFrame();

                // consume "consumer-tag" (shortstr)
                $length = \ord($this->buffer);
                $frame->consumerTag = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                // consume "deliveryTag" (longlong)
                list(, $frame->deliveryTag) = \unpack('J', $this->buffer);
                $this->buffer = \substr($this->buffer, 8);

                // consume "redelivered" (bit)
                $frame->redelivered = \ord($this->buffer) !== 0;
                $this->buffer = \substr($this->buffer, 1);

                // consume "exchange" (shortstr)
                $length = \ord($this->buffer);
                $frame->exchange = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                // consume "routing-key" (shortstr)
                $length = \ord($this->buffer);
                $frame->routingKey = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                return $frame;

                // method "basic.get-ok"
            }
            if ($method === 71) {
                $frame = new Basic\BasicGetOkFrame();

                // consume "deliveryTag" (longlong)
                list(, $frame->deliveryTag) = \unpack('J', $this->buffer);
                $this->buffer = \substr($this->buffer, 8);

                // consume "redelivered" (bit)
                $frame->redelivered = \ord($this->buffer) !== 0;
                $this->buffer = \substr($this->buffer, 1);

                // consume "exchange" (shortstr)
                $length = \ord($this->buffer);
                $frame->exchange = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                // consume "routing-key" (shortstr)
                $length = \ord($this->buffer);
                $frame->routingKey = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                // consume "messageCount" (long)
                list(, $frame->messageCount) = \unpack('N', $this->buffer);
                $this->buffer = \substr($this->buffer, 4);

                return $frame;

                // method "basic.get-empty"
            }
            if ($method === 72) {
                $frame = new Basic\BasicGetEmptyFrame();

                // consume "cluster-id" (shortstr)
                $length = \ord($this->buffer);
                $frame->clusterId = \substr($this->buffer, 1, $length);
                $this->buffer = \substr($this->buffer, 1 + $length);

                return $frame;

                // method "basic.ack"
            }
            if ($method === 80) {
                $frame = new Basic\BasicAckFrame();

                // consume "deliveryTag" (longlong)
                list(, $frame->deliveryTag) = \unpack('J', $this->buffer);
                $this->buffer = \substr($this->buffer, 8);

                // consume "multiple" (bit)
                $frame->multiple = \ord($this->buffer) !== 0;
                $this->buffer = \substr($this->buffer, 1);

                return $frame;

                // method "basic.recover-ok"
            }
            if ($method === 111) {
                return new Basic\BasicRecoverOkFrame();

                // method "basic.nack"
            }
            if ($method === 120) {
                $frame = new Basic\BasicNackFrame();

                // consume "deliveryTag" (longlong)
                list(, $frame->deliveryTag) = \unpack('J', $this->buffer);
                $this->buffer = \substr($this->buffer, 8);

                // consume "multiple" (bit)
                // consume "requeue" (bit)
                $octet = \ord($this->buffer);
                $this->buffer = \substr($this->buffer, 1);
                $frame->multiple = $octet & 1 !== 0;
                $frame->requeue = $octet & 2 !== 0;

                return $frame;
            }

            throw new AMQPProtocolException(
                'Frame method (' . $method . ') is invalid for class "basic".'
            );

            // class "tx"
        }
        if ($class === 90) {

            // method "tx.select-ok"
            if ($method === 11) {
                return new Tx\TxSelectOkFrame();

                // method "tx.commit-ok"
            }
            if ($method === 21) {
                return new Tx\TxCommitOkFrame();

                // method "tx.rollback-ok"
            }
            if ($method === 31) {
                return new Tx\TxRollbackOkFrame();
            }

            throw new AMQPProtocolException(
                'Frame method (' . $method . ') is invalid for class "tx".'
            );

            // class "confirm"
        }
        if ($class === 85) {

            // method "confirm.select-ok"
            if ($method === 11) {
                return new Confirm\ConfirmSelectOkFrame();
            }

            throw new AMQPProtocolException(
                'Frame method (' . $method . ') is invalid for class "confirm".'
            );
        }

        throw new AMQPProtocolException('Frame class (' . $class . ') is invalid.');
    }
}
