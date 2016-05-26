<?php

namespace PHPDaemon\Clients\IMAP;

use PHPDaemon\Network\Client;
use PHPDaemon\Core\Daemon;

class Pool extends Client
{
    protected function getConfigDefaults()
    {
        return [
            'port'    => 143,
            'sslport' => 993,
        ];
    }

    public function open($host, $user, $pass, $cb, $ssl = true)
    {
        $params = compact('host', 'user', 'pass', 'cb', 'ssl');
        $params['port'] = $params['ssl'] ? $this->config->sslport->value : $this->config->port->value;
        $dest = 'tcp://' . $params['host'] . ':' . $params['port'] . ($params['ssl'] ? '#ssl' : '');
        $this->getConnection($dest, function ($conn) use ($params) {
            if (!$conn || !$conn->isConnected()) {
                call_user_func($params['cb'], false);
                return;
            }
            $conn->bind('onauth', function ($conn, $ok) use ($params) {
                $conn->selectBox();
            });
            $conn->bind('onselect', function ($conn, $ok) use ($params) {
                call_user_func($params['cb'], $conn);
            });
            $conn->auth($params['user'], $params['pass']);

            //TODO: startTls
        });
    }
}
