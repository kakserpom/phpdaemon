<?php

if (!function_exists('mb_orig_substr')) {
    function mb_orig_substr(...$args)
    {
        return substr(...$args);
    }

    function mb_orig_strrpos(...$args)
    {
        return strrpos(...$args);
    }

    /**
     * @param string $haystack
     * @param mixed $needle
     * @param int $offset
     * @return bool|int
     */
    function mb_orig_strpos($haystack, $needle, $offset = 0)
    {
        return strpos($haystack, $needle, $offset);
    }

    /**
     * @param string $input
     * @return int
     */
    function mb_orig_strlen($input)
    {
        return strlen($input);
    }
}

if (!function_exists('D')) {
    function D()
    {
        \PHPDaemon\Core\Daemon::log(\PHPDaemon\Core\Debug::dump(...func_get_args()));
        //\PHPDaemon\Core\Daemon::log(\PHPDaemon\Core\Debug::backtrace());
    }
}
if (!function_exists('igbinary_serialize')) {
    function igbinary_serialize($m)
    {
        return serialize($m);
    }

    function igbinary_unserialize($m)
    {
        return unserialize($m);
    }
}
if (!function_exists('setTimeout')) {
    function setTimeout($cb, $timeout = null, $id = null, $priority = null)
    {
        return \PHPDaemon\Core\Timer::add($cb, $timeout, $id, $priority);
    }
}
if (!function_exists('clearTimeout')) {
    function clearTimeout($id)
    {
        \PHPDaemon\Core\Timer::remove($id);
    }
}
