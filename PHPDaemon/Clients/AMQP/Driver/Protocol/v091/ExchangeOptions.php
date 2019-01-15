<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091;

use PHPDaemon\Clients\AMQP\Driver\Protocol\ExchangeOptionsInterface;

/**
 * Options related to AMQP exchanges.
 *
 * Class ExchangeOptions
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091
 */
class ExchangeOptions implements ExchangeOptionsInterface
{
    /**
     * Persist the exchange across broker restarts.
     * @var bool
     */
    public $durable = false;

    /**
     * @var bool
     */
    public $passive = false;

    /**
     * Delete the exchange once it has no remaining bindings.
     *
     * This feature requires the "exchange_exchange_bindings" broker capability.
     *
     * @var bool
     */
    public $autoDelete = false;

    /**
     * Mark the exchange as internal. No messages can be published directly to
     * an internal exchange, rather it is the target for exchange-to-exchange
     * bindings.
     *
     * This feature requires the "exchange_exchange_bindings" broker capability.
     *
     * @var bool
     */
    public $internal = false;

    /**
     * @var bool
     */
    public $noWait = false;

    /**
     * QueueOptions constructor.
     * @param array $options
     */
    public function __construct(array $options = [])
    {
        if (array_key_exists('passive', $options)) {
            $this->passive = (bool)$options['passive'];
        }
        if (array_key_exists('durable', $options)) {
            $this->durable = (bool)$options['durable'];
        }
        if (array_key_exists('internal', $options)) {
            $this->internal = (bool)$options['internal'];
        }
        if (array_key_exists('autoDelete', $options)) {
            $this->autoDelete = (bool)$options['autoDelete'];
        }
        if (array_key_exists('noWait', $options)) {
            $this->noWait = (bool)$options['noWait'];
        }
    }
}
