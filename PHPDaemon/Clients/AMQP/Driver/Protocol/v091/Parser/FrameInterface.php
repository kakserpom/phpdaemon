<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Parser;

use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException;

/**
 * Produces Frame objects from binary data.
 *
 * Interface FrameInterface
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Parser
 */
interface FrameInterface
{
    /**
     * Retrieve the next frame from the internal buffer.
     *
     * @param string $buffer Binary data to feed to the parser.
     * @param int &$requiredBytes The minimum number of bytes that must be
     *                               read to produce the next frame.
     *
     * @return Frame|null            The frame parsed from the start of the buffer.
     * @throws AMQPProtocolException The incoming data does not conform to the AMQP
     *                               specification.
     */
    public function feed($buffer, &$requiredBytes = 0);
}
