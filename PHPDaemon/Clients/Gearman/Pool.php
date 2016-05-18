<?php

namespace PHPDaemon\Clients\GearmanClient;

use PHPDaemon\Config;
use PHPDaemon\Network\Client;

/**
 * Class Pool
 * @package PHPDaemon\Clients\GearmanClient
 */
class Pool extends Client
{
    /**
     * Setting default config options
     * Overriden from NetworkClient::getConfigDefaults
     * @return array|bool
     */
    protected function getConfigDefaults()
    {
        return [
            'server' => 'tcp://127.0.0.1/',
            'port' => 4730,
            'maxconnperserv' => 32,
        ];
    }
}
