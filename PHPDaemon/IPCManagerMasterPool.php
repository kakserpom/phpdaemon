<?php
namespace PHPDaemon;

use PHPDaemon\Network\Server;

/**
 * @package    Applications
 * @subpackage IPCManager
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class IPCManagerMasterPool extends Network\Server {
	public $workers = array();
}