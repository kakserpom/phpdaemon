<?php

namespace PHPDaemon\Clients\AMQP\Examples;

use PHPDaemon\Clients\AMQP\Channel;
use PHPDaemon\Clients\AMQP\Connection;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\DeliveryMode;
use PHPDaemon\Clients\AMQP\Driver\Protocol\v091\ExchangeType;
use PHPDaemon\Clients\AMQP\Message;
use PHPDaemon\Clients\AMQP\Pool;
use PHPDaemon\Core\AppInstance;
use PHPDaemon\Core\Daemon;

/**
 *
 * ## Example config file
 *
 * max-workers    1;
 * min-workers    1;
 * start-workers    1;
 * max-idle    0;
 * logging         1;
 * verbose-tty 1;
 *
 * pidfile /var/run/phpd.pid;
 * logstorage /var/log/phpdaemon.log;
 *
 * path conf/AppResolver.php;
 *
 * \PHPDaemon\Clients\AMQP\Examples\AMQPExample {}
 *
 * Pool:\PHPDaemon\Clients\AMQP\Pool {
 * server 'tcp://localhost';
 * port 5672;
 * username 'guest';
 * password 'guest';
 * vhost '/';
 * }
 */

/**
 * Class AMQPExample
 * @package PHPDaemon\Clients\AMQP\Examples
 */
class AMQPExample extends AppInstance
{

    /**
     * @var Pool
     */
    private $amqp;

    public function init()
    {
        if ($this->isEnabled()) {
            $this->amqp = Pool::getInstance();
        }
    }

    /**
     * Called when the worker is ready to go.
     * @return void
     */
    public function onReady()
    {
        if ($this->amqp) {
            $this->amqp->onReady();
            $this->connect();
        }
    }

    private function connect()
    {
        $this->amqp->getConnection(function ($connection) {
            /** @var Connection $connection */
            if (!$connection->isConnected()) {
                Daemon::$process->log('Connection with AMQP broker not established!');
                return;
            }
            $connection->on(Connection::EVENT_ON_CONNECTION_CLOSE, function ($connection, $replyCode, $replyText) {
                Daemon::$process->log(sprintf('Connection with AMQP broker was closed! Reason: %s, code:%d', $replyText, $replyCode));
            });
            $connection->on(Connection::EVENT_ON_HANDSHAKE, function ($connection, $channel) {
                /** @var $channel Channel */
                $channel->declareExchange('e.phpd.exchange', [
                    'durable' => true,
                    'type' => ExchangeType::TOPIC
                ]);

                $channel->declareQueue('q.phpd.queue', [
                    'durable' => true
                ]);
                $channel->bindQueue('q.phpd.queue', 'e.phpd.exchange', 'test', [], function () use ($connection) {
                    /** @var Connection $connection */
                    $connection->getChannel(function ($channel) {
                        /** @var $channel Channel */
                        $channel->consume('q.phpd.queue', [], function ($message) {
                            /** @var $message Message */
                            Daemon::$process->log(sprintf('Message with content "%s" received', $message->getContent()));
                            $message->ack();
                        });
                    });
                    $connection->getChannel(function ($channel) {
                        /**  @var Channel $channel */
                        $channel->publish('The test content', 'e.phpd.exchange', 'test', [
                            'deliveryMode' => DeliveryMode::PERSISTENT,
                        ]);
                    });
                });
            });
        });
    }
}