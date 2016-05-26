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
                'host'      => 'imap.yandex.ru',
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
        $this->log("Imap client READY");
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
                
                /*$conn->listFolders(
                    function ($conn, $isSuccess, $list) {
                        $this->log(print_r($list, true));
                    }
                );
                
                $conn->getUniqueId(
                    function ($conn, $isSuccess, $uids) {
                        $this->log(print_r($uids, true));
                    }
                );
                
                $conn->getSize(
                    function ($conn, $isSuccess, $uids) {
                        $this->log(print_r($uids, true));
                    }
                );
                
                $conn->countMessages(
                    function ($conn, $isSuccess, $count) {
                        $this->log(print_r($count, true));
                    }
                );*/
                
                /*$conn->removeMessage(
                    function ($conn, $isSuccess, $raw) {
                        $this->log(print_r($raw, true));
                    },
                    8
                );*/
            },
            true
        );
    }
}
