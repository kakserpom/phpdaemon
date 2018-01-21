<?php

namespace PHPDaemon\Clients\AMQP\Driver\Protocol;

use PHPDaemon\Clients\AMQP\Driver\Protocol\Exception\AMQPConnectionExceptionInterface;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\Protocol\Connection\ConnectionTuneOkFrame;

/**
 * Interface ConnectionInterface
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP\Driver\Protocol
 */
interface ConnectionInterface
{
    /**
     * ConnectionInterface constructor.
     *
     * @param mixed $connection Массив с данными подключения, стрим, и тд
     * @param \Closure $callback Коллбек, который будет вызван после успешного рукопожатия с брокером
     */
    public function __construct($connection, $callback);

    /**
     * Закрывает соединение
     *
     * @return void
     */
    public function close();

    /**
     * Возвращает ресурс или обьект транспортного уровня
     *
     * @return CommandInterface
     */
    public function getStream();

    /**
     * Возвращает обект, описывающий доступные опции для брокера
     *
     * @return FeaturesInterface
     */
    public function getFeatures();

    /**
     * Возвращает максимальный размер фрейма, полученныой из фрейма tune
     * @see ConnectionTuneOkFrame
     *
     * @return int
     */
    public function getMaximumFrameSize();

    /**
     * Ищет и возвращаяет следующий достпный номер канала.
     *
     * @return int
     * @throws AMQPConnectionExceptionInterface
     */
    public function findChannelId();
}
