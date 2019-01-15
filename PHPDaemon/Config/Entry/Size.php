<?php
namespace PHPDaemon\Config\Entry;

/**
 * Size config entry
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Size extends Generic
{

    /**
     * Converts human-readable value to plain
     * @param $value
     * @return int
     */
    public static function humanToPlain($value)
    {
        $l = substr($value, -1);

        if ($l === 'b' || $l === 'B') {
            return ((int)substr($value, 0, -1));
        }

        if ($l === 'k') {
            return ((int)substr($value, 0, -1) * 1000);
        }

        if ($l === 'K') {
            return ((int)substr($value, 0, -1) * 1024);
        }

        if ($l === 'm') {
            return ((int)substr($value, 0, -1) * 1000 * 1000);
        }

        if ($l === 'M') {
            return ((int)substr($value, 0, -1) * 1024 * 1024);
        }

        if ($l === 'g') {
            return ((int)substr($value, 0, -1) * 1000 * 1000 * 1000);
        }

        if ($l === 'G') {
            return ((int)substr($value, 0, -1) * 1024 * 1024 * 1024);
        }

        return (int)$value;
    }
}
