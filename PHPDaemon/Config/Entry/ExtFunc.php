<?php
namespace PHPDaemon\Config\Entry;

/**
 * External function config entry
 *
 * @package    Core
 * @subpackage Config
 *
 * @author     Vasily Zorin <maintainer@daemon.io>
 */
class ExtFunc extends Generic
{
    /**
     * Converts human-readable value to plain
     * @param $value
     * @return callable|null
     */
    public static function humanToPlain($value)
    {
        $cb = include($value);
        return is_callable($cb) ? $cb : null;
    }
}
