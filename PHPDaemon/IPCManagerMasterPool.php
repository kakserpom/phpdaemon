<?php
namespace PHPDaemon;

use PHPDaemon\NetworkServer;

/**
 * @package    Applications
 * @subpackage IPCManager
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class IPCManagerMasterPool extends NetworkServer {
	public $workers = array();
}