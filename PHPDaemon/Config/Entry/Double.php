<?php
namespace PHPDaemon\Config\Entry;

/**
 * Double config entry
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class Double extends Generic
{
    /**
     * Converts human-readable value to plain
     * @param $value
     * @return double
     */
    public static function humanToPlain($value)
    {
        return (double)$value;
    }
}
