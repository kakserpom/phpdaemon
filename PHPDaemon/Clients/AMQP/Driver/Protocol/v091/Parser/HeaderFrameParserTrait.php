<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Parser;

use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Basic;

/**
 * Class HeaderFrameParserTrait
 *
 * @property TableInterface tableParser
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Parser
 */
trait HeaderFrameParserTrait
{
    /**
     * @return Basic\BasicHeaderFrame
     * @throws \PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException
     */
    private function parseHeaderFrame()
    {
        $fields = \unpack('na/nb/Jc/nd', $this->buffer);
        $this->buffer = mb_orig_substr($this->buffer, 14);

        $class = $fields['a'];
        $flags = $fields['d'];

        // class "basic"
        if ($class === 60) {
            $frame = new Basic\BasicHeaderFrame();
            $frame->contentLength = $fields['c'];

            // consume "content-type" (shortstr)
            if ($flags & 32768) {
                $length = \ord($this->buffer);
                $frame->contentType = mb_orig_substr($this->buffer, 1, $length);
                $this->buffer = mb_orig_substr($this->buffer, 1 + $length);
            }

            // consume "content-encoding" (shortstr)
            if ($flags & 16384) {
                $length = \ord($this->buffer);
                $frame->contentEncoding = mb_orig_substr($this->buffer, 1, $length);
                $this->buffer = mb_orig_substr($this->buffer, 1 + $length);
            }

            // consume "headers" (table)e
            if ($flags & 8192) {
                $frame->headers = $this->tableParser->parse($this->buffer);
            }

            // consume "delivery-mode" (octet)
            if ($flags & 4096) {
                list(, $frame->deliveryMode) = \unpack('c', $this->buffer);
                $this->buffer = mb_orig_substr($this->buffer, 1);
            }

            // consume "priority" (octet)
            if ($flags & 2048) {
                list(, $frame->priority) = \unpack('c', $this->buffer);
                $this->buffer = mb_orig_substr($this->buffer, 1);
            }

            // consume "correlation-id" (shortstr)
            if ($flags & 1024) {
                $length = \ord($this->buffer);
                $frame->correlationId = mb_orig_substr($this->buffer, 1, $length);
                $this->buffer = mb_orig_substr($this->buffer, 1 + $length);
            }

            // consume "reply-to" (shortstr)
            if ($flags & 512) {
                $length = \ord($this->buffer);
                $frame->replyTo = mb_orig_substr($this->buffer, 1, $length);
                $this->buffer = mb_orig_substr($this->buffer, 1 + $length);
            }

            // consume "expiration" (shortstr)
            if ($flags & 256) {
                $length = \ord($this->buffer);
                $frame->expiration = mb_orig_substr($this->buffer, 1, $length);
                $this->buffer = mb_orig_substr($this->buffer, 1 + $length);
            }

            // consume "message-id" (shortstr)
            if ($flags & 128) {
                $length = \ord($this->buffer);
                $frame->messageId = mb_orig_substr($this->buffer, 1, $length);
                $this->buffer = mb_orig_substr($this->buffer, 1 + $length);
            }

            // consume "timestamp" (timestamp)
            if ($flags & 64) {
                list(, $frame->timestamp) = \unpack('J', $this->buffer);
                $this->buffer = mb_orig_substr($this->buffer, 8);
            }

            // consume "type" (shortstr)
            if ($flags & 32) {
                $length = \ord($this->buffer);
                $frame->type = mb_orig_substr($this->buffer, 1, $length);
                $this->buffer = mb_orig_substr($this->buffer, 1 + $length);
            }

            // consume "user-id" (shortstr)
            if ($flags & 16) {
                $length = \ord($this->buffer);
                $frame->userId = mb_orig_substr($this->buffer, 1, $length);
                $this->buffer = mb_orig_substr($this->buffer, 1 + $length);
            }

            // consume "app-id" (shortstr)
            if ($flags & 8) {
                $length = \ord($this->buffer);
                $frame->appId = mb_orig_substr($this->buffer, 1, $length);
                $this->buffer = mb_orig_substr($this->buffer, 1 + $length);
            }

            // consume "cluster-id" (shortstr)
            if ($flags & 4) {
                $length = \ord($this->buffer);
                $frame->clusterId = mb_orig_substr($this->buffer, 1, $length);
                $this->buffer = mb_orig_substr($this->buffer, 1 + $length);
            }

            return $frame;
        }

        throw new AMQPProtocolException(
            'Frame class (' . $class . ') is invalid or does not support content frames.'
        );
    }
}
