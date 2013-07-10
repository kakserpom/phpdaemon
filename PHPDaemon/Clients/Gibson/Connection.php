<?php
namespace PHPDaemon\Clients\Gibson;

use PHPDaemon\Core\Daemon;
use PHPDaemon\Network\ClientConnection;

class Connection extends ClientConnection {
	    public $error; // error message

	protected function onRead() {
        Daemon::log('GIbson Read '. $this->readLine());
	}
}
