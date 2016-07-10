<?php
namespace Examples;

use PHPDaemon\Core\Daemon;

class ExampleIMAPClient extends \PHPDaemon\Core\AppInstance
{
    /**
    * Setting default config options
    * @return array|bool
    */
    protected function getConfigDefaults()
    {
        return [
                'host'      => '',
                'login'     => '',
                'password'  => ''
        ];
    }

    /**
     * Called when the worker is ready to go.
     * @return void
     */
    public function onReady()
    {
        \PHPDaemon\Clients\IMAP\Pool::getInstance()->open(
            $this->config->host->value,
            $this->config->login->value,
            $this->config->password->value,
            function ($conn) {
                if (!$conn) {
                    $this->log('Fail to open IMAP connection');
                    return;
                }
                $this->log('open IMAP connection success');
                $conn->getRawMessage(
                    function ($conn, $isSuccess, $raw) {
                        $this->log(print_r($raw, true));
                        $conn->logout();
                    },
                    1,
                    false
                );
            },
            true
        );
    }
}
