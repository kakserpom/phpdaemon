<?php
namespace PHPDaemon\Clients\Gibson;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Core\Debug;
use PHPDaemon\Network\ClientConnection;

class Connection extends ClientConnection {
	    public $error; // error message

	protected function onRead() {
        Daemon::log('GIbson Read '. Debug::exportBytes($this->look(1024)));
	}

    public function onReady() {
        parent::onReady();
        if ($this->url === null) {
            return;
        }
        if ($this->connected && !$this->busy) {
            $this->pool->markConnFree($this, $this->url);
        }
    }
}
