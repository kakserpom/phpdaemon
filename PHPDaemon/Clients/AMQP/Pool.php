<?php

namespace PHPDaemon\Clients\AMQP;

use PHPDaemon\Network\Client;

/**
 * Class Pool
 * @author Aleksey I. Kuleshov YOU GLOBAL LIMITED
 * @package PHPDaemon\Clients\AMQP
 */
class Pool extends Client
{
    /**
     *
     */
    protected function init()
    {
        $this->setConnectionClass(Connection::class);
        parent::init();
    }

    /**
     * Setting default config options
     * @return array
     */
    protected function getConfigDefaults()
    {
        return [
            'host' => '127.0.0.1',
            'port' => 5672,
            'username' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
        ];
    }
}
