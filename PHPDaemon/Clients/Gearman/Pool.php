<?php
namespace PHPDaemon\Clients\Gearman;

use PHPDaemon\Network\Client;
use PHPDaemon\Config;
use PHPDaemon\Core\Daemon;


class Pool extends Client {

    /**
     * Setting default config options
     * Overriden from NetworkClient::getConfigDefaults
     * @return array|bool
     */
    protected function getConfigDefaults() {
        return [
            'server'         => 'tcp://127.0.0.1/',
            'port'           => 4730,
            'maxconnperserv' => 32,
        ];
    }
}