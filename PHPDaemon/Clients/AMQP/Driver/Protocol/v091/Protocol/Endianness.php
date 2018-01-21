<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol;

/**
 * Class Endianness
 *
 * @method static Endianness LITTLE() bool
 * @method static Endianness BIG() bool
 *
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol
 */
class Endianness
{

    /**
     * @param $name
     * @param $arguments
     * @return bool
     * @throws \BadMethodCallException
     */
    public static function __callStatic($name, $arguments)
    {
        $result = (pack('S', 1) === pack('v', 1));

        if ($name === 'LITTLE') {
            return $result;
        }
        if ($name === 'BIG') {
            return !$result;
        }

        throw new \BadMethodCallException(
            sprintf('Static method %s not found', $name));
    }
}
