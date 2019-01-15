<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol;

use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\OutgoingFrame;

/**
 * Interface CommandInterface
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol
 */
interface CommandInterface
{
    /**
     * @param OutgoingFrame $frame
     * @param callable|null $callback
     */
    public function command(OutgoingFrame $frame, callable $callback = null);
}
