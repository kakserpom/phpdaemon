<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Parser;

use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPProtocolException;

/**
 * Parse an AMQP table from a string buffer.
 *
 * Interface TableParser
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Parser
 */
interface TableInterface
{
    /**
     * Retrieve the next frame from the internal buffer.
     *
     * @param string &$buffer Binary data containing the table.
     *
     * @return array             The table.
     * @throws AMQPProtocolException if the incoming data does not conform to the
     *                               AMQP specification.
     */
    public function parse(&$buffer);
}
