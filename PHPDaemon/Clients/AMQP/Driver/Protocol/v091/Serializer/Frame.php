<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Serializer;

/**
 * Serializes frames to binary data.
 *
 * Most of this class' logic is in traits that are generated automatically.
 */
class Frame implements FrameInterface
{
    use ScalarSerializerTrait, FrameSerializerTrait;

    /**
     * @var TableInterface The serializer used to serialize AMQP tables.
     */
    private $tableSerializer;

    /**
     * @param TableInterface $tableSerializer The serializer used to serialize AMQP tables.
     */
    public function __construct(TableInterface $tableSerializer)
    {
        $this->tableSerializer = $tableSerializer;
    }
}
