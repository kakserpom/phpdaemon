<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Serializer;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Endianness;

/**
 * Serialize scalar values from to a binary buffer.
 *
 * Class ScalarSerializerTrait
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Serializer
 */
trait ScalarSerializerTrait
{
    /**
     * Serialize a string as an AMQP "short" string.
     *
     * 1-byte length (in bytes), followed by UTF-8 string data.
     *
     * @param string $value The string to serialize.
     *
     * @return string The serialized string.
     */
    private function serializeShortString($value)
    {
        return chr(strlen($value)) . $value;
    }

    /**
     * Serialize a string as an AMQP short string.
     *
     * 4-byte length (in bytes), followed by UTF-8 string data.
     *
     * @param string $value The string to serialize.
     *
     * @return string The serialized string.
     */
    private function serializeLongString($value)
    {
        return pack('N', strlen($value)) . $value;
    }

    /**
     * Serialize a 8-bit signed integer.
     *
     * @param integer $value The value to serialize.
     *
     * @return string The serialized value.
     */
    private function serializeSignedInt8($value)
    {
        if ($value < 0) {
            $value += 0x100;
        }

        return chr($value);
    }

    /**
     * Serialize a 16-bit signed integer.
     *
     * @param integer $value The value to serialize.
     *
     * @return string The serialized value.
     */
    private function serializeSignedInt16($value)
    {
        if ($value < 0) {
            $value += 0x10000;
        }

        return pack('n', $value);
    }

    /**
     * Serialize a 32-bit signed integer.
     *
     * @param integer $value The value to serialize.
     *
     * @return string The serialized value.
     */
    private function serializeSignedInt32($value)
    {
        if ($value < 0) {
            $value += 0x100000000;
        }

        return pack('N', $value);
    }

    /**
     * Serialize a 64-bit signed integer.
     *
     * @param integer $value The value to serialize.
     *
     * @return string The serialized value.
     */
    private function serializeSignedInt64($value)
    {
        return pack('J', $value);
    }

    /**
     * Serialize a double (8-byte).
     *
     * @param float $value The value to serialize.
     *
     * @return string The serialized value.
     */
    public function serializeDouble($value)
    {
        if (Endianness::LITTLE()) {
            return strrev(pack('d', $value));
        }
        return pack('d', $value);
    }
}
