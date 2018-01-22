<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol\v091;

use PHPDaemon\Clients\AMQP\Driver\Protocol\QueueOptionsInterface;

/**
 * Options related to queues.
 *
 * Class QueueOptions
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol\v091
 */
class QueueOptions implements QueueOptionsInterface
{

    /**
     * @var bool
     */
    public $passive = false;

    /**
     * Persist the queue (but not necessarily the messages on it) across broker
     * restarts.
     *
     * @var bool
     */
    public $durable = false;

    /**
     * Restrict access to the queue to the connection used to declare it.
     *
     * @var bool
     */
    public $exclusive = false;

    /**
     * Delete the queue once all consumers have been cancelled.
     *
     * @var bool
     */
    public $autoDelete = false;

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
        if (array_key_exists('exclusive', $options)) {
            $this->exclusive = (bool)$options['exclusive'];
        }
        if (array_key_exists('autoDelete', $options)) {
            $this->autoDelete = (bool)$options['autoDelete'];
        }
        if (array_key_exists('noWait', $options)) {
            $this->noWait = (bool)$options['noWait'];
        }
    }
}
