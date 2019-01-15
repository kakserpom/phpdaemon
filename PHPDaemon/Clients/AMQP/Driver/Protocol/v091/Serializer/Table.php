<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Serializer;

use InvalidArgumentException;

/**
 * Serialize an AMQP table to a string buffer.
 *
 * This implementation uses the field types as discussed in the AMQP 0.9.1 SIG,
 * (*NOT* the specification) along with Qpid's extensions. This serializer is
 * suitable for use with RabbitMQ and Qpid.
 *
 * @see https://www.rabbitmq.com/amqp-0-9-1-errata.html#section_3
 *
 * @see SpecTableSerializer for an implementation based on the AMQP 0.9.1 specification.
 */
class Table implements TableInterface
{
    use ScalarSerializerTrait;

    /**
     * Serialize an AMQP table.
     *
     * @param array $table The table.
     *
     * @return string                   The binary serialized table.
     * @throws InvalidArgumentException if the table contains unserializable
     *                                  values.
     */
    public function serialize(array $table)
    {
        $buffer = '';

        foreach ($table as $key => $value) {
            $buffer .= $this->serializeShortString($key);
            $buffer .= $this->serializeField($value);
        }

        return $this->serializeByteArray($buffer);
    }

    /**
     * Serialize a table or array field.
     *
     * @param mixed $value
     *
     * @return string The serialized value.
     * @throws \InvalidArgumentException
     */
    private function serializeField($value)
    {
        if (is_string($value)) {
            // @todo Could be decimal (D) or byte array (x)
            // @see https://github.com/recoilphp/amqp/issues/25
            return 'S' . $this->serializeLongString($value);
        }
        if (is_int($value)) {
            // @todo Could be timestamp (T)
            // @see https://github.com/recoilphp/amqp/issues/25
            if ($value >= 0) {
                if ($value < 0x80) {
                    return 'b' . $this->serializeSignedInt8($value);
                }
                if ($value < 0x8000) {
                    return 's' . $this->serializeSignedInt16($value);
                }
                if ($value < 0x80000000) {
                    return 'I' . $this->serializeSignedInt32($value);
                }
            } else {
                if ($value >= -0x80) {
                    return 'b' . $this->serializeSignedInt8($value);
                }
                if ($value >= -0x8000) {
                    return 's' . $this->serializeSignedInt16($value);
                }
                if ($value >= -0x80000000) {
                    return 'I' . $this->serializeSignedInt32($value);
                }
            }

            return 'l' . $this->serializeSignedInt64($value);
        }
        if (true === $value) {
            return "t\x01";
        }
        if (false === $value) {
            return "t\x00";
        }
        if (null === $value) {
            return 'V';
        }
        if (is_float($value)) {
            return 'd' . $this->serializeDouble($value);
        }
        if (is_array($value)) {
            return $this->serializeArrayOrTable($value);
        }
        throw new InvalidArgumentException(
            'Could not serialize value (' . chr($value) . ').'
        );
    }

    /**
     * Serialize a PHP array.
     *
     * If the array contains sequential integer keys, it is serialized as an AMQP
     * array, otherwise it is serialized as an AMQP table.
     *
     * @param array $array
     *
     * @return string The binary serialized table.
     * @throws \InvalidArgumentException
     */
    private function serializeArrayOrTable(array $array)
    {
        $assoc = false;
        $index = 0;
        $values = [];

        foreach ($array as $key => $value) {
            // We already know the array is associative, serialize both the key
            // and the value ...
            if ($assoc) {
                $values[] = $this->serializeShortString($key)
                    . $this->serializeField($value);

                // Otherwise, if the key matches the index counter it is sequential,
                // only serialize the value ...
            } elseif ($key === $index++) {
                $values[] = $this->serializeField($value);

                // Otherwise, we've just discovered the array is NOT sequential,
                // Go back through the existing values and add the keys ...
            } else {
                foreach ($values as $k => $v) {
                    $values[$k] = $this->serializeShortString($k) . $v;
                }

                $values[] = $this->serializeShortString($key)
                    . $this->serializeField($value);

                $assoc = true;
            }
        }

        return ($assoc ? 'F' : 'A') . $this->serializeByteArray(
                implode('', $values)
            );
    }

    /**
     * Serialize a byte-array.
     *
     * @param string $value The value to serialize.
     *
     * @return string The serialized value.
     */
    private function serializeByteArray($value)
    {
        return pack('N', strlen($value)) . $value;
    }
}
