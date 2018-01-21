<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol;

/**
 * An application-managed AMQP channel.
 *
 * Channels are "virtual connections" within an AMQP connection. All AMQP
 * operations, such as declaring exchanges and queues, publishing and consuming
 * messages, etc are performed on a channel.
 *
 * If an error occurs (such as attempting to publish to a non-existent exchange),
 * the channel on which th request was made is closed.
 *
 * Channels can be managed by the application (application-managed) or by
 * the AMQP library (auto-managed).
 *
 * All operations that communicate over a channel accept an optional
 * $channel parameter, which may be left null to use an auto-managed channel.
 *
 * Interface Channel
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol
 */
interface ChannelInterface
{
    /**
     * ChannelInterface constructor.
     *
     * @param ConnectionInterface $connection
     * @param \Closure|null $callback
     */
    public function __construct(ConnectionInterface $connection, $callback = null);

    /**
     * Возвращает ID канала
     *
     * @return int
     */
    public function getId();

    /**
     * Закрывает канал
     *
     * @return void
     */
    public function close();

    /**
     * Возвращает экземпляр обьекта ConnectionInterface
     *
     * @return ConnectionInterface
     */
    public function getConnection();

    /**
     * Возвращает сотсояние канала
     *
     * @return bool
     */
    public function isConnected();
}
