<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091;

use PHPDaemon\Clients\AMQP\Driver\Protocol\DeliveryModeInterface;

/**
 * The delivery mode controls message persistence on a per-message basis.
 *
 * Class DeliveryMode
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091
 */
class DeliveryMode implements DeliveryModeInterface
{
    /**
     * Store the messages in volatile memory on the broker.
     */
    const NON_PERSISTENT = 1;

    /**
     * Persist the message to disk on the broker, if it is routed to a durable
     * queue or queues.
     *
     * @see QueueOptions::$durable
     */
    const PERSISTENT = 2;
}
