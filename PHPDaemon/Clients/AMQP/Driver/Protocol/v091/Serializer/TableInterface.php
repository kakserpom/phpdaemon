<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Serializer;

use InvalidArgumentException;

/**
 * Serialize an AMQP table to a string buffer.
 *
 * Interface TableInterface
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Serializer
 */
interface TableInterface
{
    /**
     * Serialize an AMQP table.
     *
     * @param array $table The table.
     *
     * @return string                   The binary serialized table.
     * @throws InvalidArgumentException if the table contains unserializable
     *                                  values.
     */
    public function serialize(array $table);
}
