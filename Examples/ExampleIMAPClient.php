<?php
namespace Examples;

use PHPDaemon\Core\Daemon;

class ExampleIMAPClient extends \PHPDaemon\Core\AppInstance
{
    public $enableRPC = true;
   
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
        Daemon::log("Imap client READY");
        \PHPDaemon\Clients\IMAP\Pool::getInstance()->open(
            $this->config->host->value,
            $this->config->login->value,
            $this->config->password->value,
            function ($conn) {
                if (!$conn) {
                    Daemon::log('Fail to open IMAP connaction');
                    return;
                }
                Daemon::log('open IMAP connection success');
                $conn->bind('onlist', function ($conn, $ok, $list) {
                    Daemon::log('onlist: ' . $ok);
                    Daemon::log(print_r($list, true));
                    $conn->logout();
                });
                $conn->bind('onrawmessage', function ($conn, $ok, $raw) {
                    Daemon::log('onrawmessage: ' . $ok);
                    Daemon::log(print_r($raw, true));
                    
                    $conn->logout();
                });
                $conn->bind('ongetuid', function ($conn, $ok, $uids) {
                    Daemon::log('ongetuid uids: ');
                    Daemon::log($uids);
                });
                $conn->bind('onremovemessage', function ($conn, $ok, $raw) {
                    Daemon::log('onremovemessage: '  . $ok);
                    Daemon::log($raw);
                });
                
                $conn->getRawMessage(1, false);
                //$conn->getUniqueId();
                //$conn->listFolders();
                //$conn->removeMessage();
            },
            true
        );
    }
}
