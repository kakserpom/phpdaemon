<?php
namespace PHPDaemon;

/**
 * @package    Applications
 * @subpackage IPCManager
 *
 * @author     Zorin Vasily <maintainer@daemon.io>
 */
class IPCManagerMasterPool extends \PHPDaemon\Network\Server {
	public $workers = array();
	public $connectionClass = '\PHPDaemon\IPCManagerMasterPoolConnection';
}