<?php
namespace PHPDaemon\Clients\Gibson;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Network\ClientConnection;

class Connection extends ClientConnection {
	    public $error; // error message

	protected function onRead() {
        Daemon::log('GIbson Read '. $this->readLine());
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
